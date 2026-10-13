<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Models\PaymentWebhookEvent;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

                if ($intent->status === 'paid') {
                    if ($intent->provider_transaction_id !== $providerTransactionId) {
                        throw new ConflictHttpException('Payment intent was already settled by another provider transaction.');
                    }

                    $event->forceFill([
                        'outcome' => 'duplicate_payment',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'duplicate_payment', 'duplicate' => true];
                }

                if ($intent->status !== 'pending' || ($intent->expires_at && $intent->expires_at->isPast())) {
                    $event->forceFill([
                        'outcome' => 'rejected',
                        'payment_intent_id' => $intent->id,
                        'processed_at' => now(),
                    ])->save();

                    return ['outcome' => 'rejected', 'duplicate' => false];
                }

                if ($intent->amount_minor !== $amount || $intent->currency !== strtoupper($currency)) {
                    throw new ConflictHttpException('Payment amount or currency does not match the intent.');
                }

                $intent->forceFill([
                    'status' => 'paid',
                    'provider_transaction_id' => $providerTransactionId,
                    'paid_at' => now(),
                ])->save();

                $this->ledger->post(
                    walletId: $intent->wallet_id,
                    type: 'credit',
                    amountMinor: $intent->amount_minor,
                    idempotencyKey: 'funding:'.$provider.':'.$providerTransactionId,
                    reference: $intent->reference,
                    description: 'Verified wallet funding',
                    metadata: [
                        'payment_intent_id' => $intent->public_id,
                        'provider' => $provider,
                        'provider_transaction_id' => $providerTransactionId,
                    ],
                    correlationId: (string) Str::uuid(),
                );

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

            return ['outcome' => $existing->outcome, 'duplicate' => true];
        }
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        return in_array($sqlState, ['23000', '23505'], true);
    }
}