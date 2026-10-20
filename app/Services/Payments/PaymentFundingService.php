<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use App\Services\Wallets\WalletLedgerService;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PaymentFundingService
{
    public function __construct(private readonly WalletLedgerService $ledger)
    {
    }

    /**
     * Expected normalized event: id, type, data.reference, data.transaction_id,
     * data.status, data.amount_minor, data.currency. Call only after signature verification.
     *
     * @param array<string, mixed> $payload
     * @return array{outcome:string, duplicate:bool}
     */
    public function processVerifiedEvent(string $provider, array $payload): array
    {
        $eventId = $payload['id'] ?? null;
        $eventType = $payload['type'] ?? null;
        $data = $payload['data'] ?? null;

        if (! is_string($eventId) || $eventId === '' || strlen($eventId) > 190
            || ! is_string($eventType) || $eventType === ''
            || ! is_array($data)) {
            throw new UnprocessableEntityHttpException('Webhook event schema is invalid.');
        }

        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($provider, $eventId, $eventType, $data, $payloadHash, $payload): array {
                $event = PaymentWebhookEvent::query()->create([
                    'provider' => $provider,
                    'event_id' => $eventId,
                    'payload_hash' => $payloadHash,
                    'event_type' => $eventType,
                    'outcome' => 'received',
                    'safe_payload' => [
                        'id' => $eventId,
                        'type' => $eventType,
                        'reference' => is_string($data['reference'] ?? null) ? $data['reference'] : null,
                        'transaction_id' => is_string($data['transaction_id'] ?? null) ? $data['transaction_id'] : null,
                        'status' => is_string($data['status'] ?? null) ? $data['status'] : null,
                    ],
                ]);

                if ($eventType !== 'payment.succeeded' || ($data['status'] ?? null) !== 'succeeded') {
                    $event->forceFill(['outcome' => 'ignored', 'processed_at' => now()])->save();

                    return ['outcome' => 'ignored', 'duplicate' => false];
                }

                $reference = $data['reference'] ?? null;
                $providerTransactionId = $data['transaction_id'] ?? null;
                $amount = $data['amount_minor'] ?? null;
                $currency = $data['currency'] ?? null;

                if (! is_string($reference) || $reference === ''
                    || ! is_string($providerTransactionId) || $providerTransactionId === ''
                    || ! is_int($amount) || $amount < 1
                    || ! is_string($currency)) {
                    throw new UnprocessableEntityHttpException('Successful payment event is missing required fields.');
                }

                /** @var PaymentIntent|null $intent */
                $intent = PaymentIntent::query()->where('reference', $reference)->lockForUpdate()->first();
                if ($intent === null || $intent->provider !== $provider) {
                    throw new UnprocessableEntityHttpException('Payment reference was not found for this provider.');
                }


                // Validate financial fields before any duplicate or terminal-status shortcut.
                if ($intent->amount_minor !== $amount || $intent->currency !== strtoupper($currency)) {
                    throw new ConflictHttpException('Payment amount or currency does not match the intent.');
                }

                // Different event IDs can describe the same provider transaction. The intent row lock
                // serializes them; only the original settlement may credit the wallet.
                if (in_array($intent->status, ['paid', 'test_paid'], true)) {
                    if ($intent->provider_transaction_id !== $providerTransactionId) {
                        throw new ConflictHttpException('Payment intent was already settled by another provider transaction.');
                    }

                    $duplicateOutcome = $intent->status === 'test_paid' ? 'test_succeeded' : 'duplicate_payment';
                    $event->forceFill([
                        'outcome' => $duplicateOutcome,
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => $duplicateOutcome, 'duplicate' => true];
                }

                // A provider-verified Paystack success must still be reconciled after checkout expiry.
                if ($intent->status !== 'pending' || ($provider !== 'paystack' && $intent->expires_at && $intent->expires_at->isPast())) {
                    $event->forceFill([
                        'outcome' => 'rejected',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'rejected', 'duplicate' => false];
                }
                // Live settlements remain fail-closed until the payment release gate is explicitly opened.
                // Throwing rolls back the webhook event too, allowing safe reconciliation/retry later.
                if (($intent->metadata['gateway_mode'] ?? null) === 'live'
                    && ! config('services.semizzy_payments.live_funding_enabled', false)) {
                    throw new LogicException('Live wallet funding settlement is disabled until payment safety checks pass.');
                }

                // Test-mode successes are recorded for end-to-end verification, but must never
                // become real production wallet funds. Only explicitly selected Live intents post to the ledger.
                if ($provider === 'paystack' && ($intent->metadata['gateway_mode'] ?? null) === 'test') {
                    $intent->forceFill([
                        'status' => 'test_paid',
                        'provider_transaction_id' => $providerTransactionId,
                        'paid_at' => now(),
                    ])->save();

                    $event->forceFill([
                        'outcome' => 'test_succeeded',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'test_succeeded', 'duplicate' => false];
                }

                try {
                    $this->ledger->post(
                        walletId: $intent->wallet_id,
                        type: 'credit',
                        amountMinor: $intent->amount_minor,
                        idempotencyKey: 'funding:'.hash('sha256', $provider.':'.$providerTransactionId),
                        reference: $intent->reference,
                        description: 'Verified wallet funding',
                        metadata: [
                            'payment_intent_id' => $intent->public_id,
                            'provider' => $provider,
                            'provider_transaction_id' => $providerTransactionId,
                        ],
                        correlationId: (string) Str::uuid(),
                    );
                } catch (DomainException $exception) {
                    if (! str_contains($exception->getMessage(), 'account level would be exceeded')) {
                        throw $exception;
                    }

                    // The gateway payment is verified but could not be credited.
                    // Persist it for reconciliation instead of losing it or calling it settled.
                    $intent->forceFill([
                        'status' => 'review_required',
                        'provider_transaction_id' => $providerTransactionId,
                        'paid_at' => now(),
                    ])->save();
                    $event->forceFill([
                        'outcome' => 'wallet_limit_review',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'wallet_limit_review', 'duplicate' => false];
                }

                $intent->forceFill([
                    'status' => 'paid',
                    'provider_transaction_id' => $providerTransactionId,
                    'paid_at' => now(),
                ])->save();
                $event->forceFill([
                    'outcome' => 'credited',
                    'payment_intent_id' => $intent->id,
                    'processed_at' => now(),
                ])->save();

                return ['outcome' => 'credited', 'duplicate' => false];
            }, attempts: 3);
        } catch (QueryException $exception) {
            // A concurrent delivery can win the unique (provider, event_id) insert.
            if (! $this->isUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = PaymentWebhookEvent::query()
                ->where('provider', $provider)
                ->where('event_id', $eventId)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                throw new ConflictHttpException('Webhook event ID was replayed with a different payload.');
            }

            // Older deployments may have recorded a verified Paystack success as rejected
            // solely because the local checkout intent expired. Retry that exact event when
            // its intent is still pending; settlement remains protected by row locks and ledger idempotency.
            if ($provider === 'paystack' && $existing->outcome === 'rejected' && $existing->payment_intent_id !== null) {
                $intentStatus = PaymentIntent::query()->whereKey($existing->payment_intent_id)->value('status');
                if ($intentStatus === 'pending') {
                    $existing->delete();

                    return $this->processVerifiedEvent($provider, $payload);
                }
            }

            return ['outcome' => $existing->outcome, 'duplicate' => true];
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}