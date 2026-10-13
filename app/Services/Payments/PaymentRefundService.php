<?php

namespace App\Services\Payments;

use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class PaymentRefundService
{
    public function __construct(
        private readonly WalletLedgerService $ledger,
        private readonly PaystackClient $paystack,
    ) {
    }

    public function request(User $customer, string $intentPublicId, int $amountMinor, string $reason, string $idempotencyKey): PaymentRefund
    {
        if ($amountMinor < 1 || trim($reason) === '' || mb_strlen($reason) > 500
            || $idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Refund amount, reason, or idempotency key is invalid.');
        }

        return DB::transaction(function () use ($customer, $intentPublicId, $amountMinor, $reason, $idempotencyKey): PaymentRefund {
            $intent = PaymentIntent::query()
                ->where('public_id', $intentPublicId)
                ->lockForUpdate()
                ->first();

            if ($intent === null || $intent->wallet()->value('user_id') !== $customer->id) {
                throw new UnprocessableEntityHttpException('Paid payment intent was not found.');
            }
            if ($intent->provider !== 'paystack' || $intent->status !== 'paid') {
                throw new UnprocessableEntityHttpException('Only completed Paystack funding payments can be refunded.');
            }

            $existing = PaymentRefund::query()
                ->where('requested_by_user_id', $customer->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if ($existing->payment_intent_id !== $intent->id || $existing->amount_minor !== $amountMinor
                    || $existing->reason !== trim($reason)) {
                    throw new ConflictHttpException('Refund idempotency key was reused with different details.');
                }

                return $existing;
            }

            $reserved = (int) PaymentRefund::query()
                ->where('payment_intent_id', $intent->id)
                ->whereNotIn('status', ['failed', 'rejected', 'cancelled'])
                ->sum('amount_minor');

            if ($amountMinor > $intent->amount_minor || $reserved > $intent->amount_minor - $amountMinor) {
                throw new UnprocessableEntityHttpException('Requested refunds exceed the original payment amount.');
            }

            return PaymentRefund::query()->create([
                'public_id' => (string) Str::uuid(),
                'payment_intent_id' => $intent->id,
                'requested_by_user_id' => $customer->id,
                'idempotency_key' => $idempotencyKey,
                'amount_minor' => $amountMinor,
                'reason' => trim($reason),
                'status' => 'requested',
                'metadata' => ['currency' => $intent->currency],
            ]);
        }, attempts: 3);
    }

    /**
     * Reserve the refund amount from the customer's available wallet balance before
     * contacting Paystack. If funds have already been spent, no provider call is made.
     */
    public function approve(string $publicId, User $approver): PaymentRefund
    {
        try {
            $refund = DB::transaction(function () use ($publicId, $approver): PaymentRefund {
                $refund = PaymentRefund::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
                if ($refund->status !== 'requested') {
                    throw new ConflictHttpException('Only requested refunds can be approved.');
                }

                $intent = PaymentIntent::query()->whereKey($refund->payment_intent_id)->lockForUpdate()->firstOrFail();
                $reserved = (int) PaymentRefund::query()
                    ->where('payment_intent_id', $intent->id)
                    ->whereNotIn('status', ['failed', 'rejected', 'cancelled'])
                    ->sum('amount_minor');

                if ($intent->status !== 'paid' || $reserved > $intent->amount_minor) {
                    throw new ConflictHttpException('Refund is no longer eligible for settlement.');
                }

                $debit = $this->ledger->post(
                    walletId: $intent->wallet_id,
                    type: 'debit',
                    amountMinor: $refund->amount_minor,
                    idempotencyKey: 'refund-reserve:'.$refund->public_id,
                    reference: $intent->reference,
                    description: 'Approved payment refund reservation',
                    actorUserId: $approver->id,
                    metadata: [
                        'payment_refund_id' => $refund->public_id,
                        'payment_intent_id' => $intent->public_id,
                        'reason' => $refund->reason,
                    ],
                    correlationId: $refund->public_id,
                );

                $refund->forceFill([
                    'approved_by_user_id' => $approver->id,
                    'wallet_debit_transaction_id' => $debit->id,
                    'status' => 'processing',
                    'approved_at' => now(),
                ])->save();

                return $refund->fresh();
            }, attempts: 3);
        } catch (InsufficientWalletFunds $exception) {
            PaymentRefund::query()->where('public_id', $publicId)->where('status', 'requested')->update([
                'status' => 'blocked_insufficient_wallet_funds',
                'metadata' => DB::raw("JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.settlement_block', 'insufficient_available_balance')"),
                'updated_at' => now(),
            ]);

            return PaymentRefund::query()->where('public_id', $publicId)->firstOrFail();
        }

        try {
            $intent = $refund->paymentIntent()->firstOrFail();
            $providerData = $this->paystack->refund($intent->reference, $refund->amount_minor, $refund->reason);
            $providerStatus = strtolower((string) ($providerData['status'] ?? 'pending'));
            $providerRef = $providerData['refund_reference'] ?? $providerData['id'] ?? null;

            return $this->applyStatus(
                $refund->public_id,
                $providerStatus,
                is_scalar($providerRef) ? (string) $providerRef : null,
                ['provider_response_status' => $providerStatus],
            );
        } catch (LogicException $exception) {
            // A timeout/ambiguous response must not trigger a second refund or restore
            // the wallet until Paystack's actual outcome has been reconciled.
            report($exception);

            return $this->markReviewRequired($refund->public_id, 'provider_result_unknown');
        }
    }

    /**
     * Match signed Paystack refund notifications to our approved refund request.
     * Returns false when the event cannot be uniquely matched.
     *
     * @param array<string, mixed> $payload
     */
    public function applyPaystackEvent(string $eventType, array $payload): bool
    {
        if (! str_starts_with($eventType, 'refund.')) {
            return false;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $providerRef = $data['refund_reference'] ?? $data['id'] ?? null;
        $providerRef = is_scalar($providerRef) ? (string) $providerRef : null;
        $transactionRef = $data['transaction_reference'] ?? $data['reference'] ?? null;

        $query = PaymentRefund::query();
        if ($providerRef !== null) {
            $query->where('provider_refund_reference', $providerRef);
        } elseif (is_string($transactionRef) && $transactionRef !== '') {
            $intent = PaymentIntent::query()->where('provider', 'paystack')->where('reference', $transactionRef)->first();
            if ($intent === null) {
                return false;
            }
            $query->where('payment_intent_id', $intent->id)
                ->whereIn('status', ['processing', 'provider_pending', 'review_required']);
        } else {
            return false;
        }

        $matches = $query->limit(2)->get();
        if ($matches->count() !== 1) {
            return false;
        }

        $status = match ($eventType) {
            'refund.pending', 'refund.processing' => 'pending',
            'refund.needs-attention' => 'needs-attention',
            'refund.failed' => 'failed',
            'refund.processed' => 'processed',
            default => null,
        };
        if ($status === null) {
            return false;
        }

        $this->applyStatus(
            $matches->first()->public_id,
            $status,
            $providerRef,
            ['last_provider_event' => $eventType],
        );

        return true;
    }

    private function markReviewRequired(string $publicId, string $reason): PaymentRefund
    {
        return DB::transaction(function () use ($publicId, $reason): PaymentRefund {
            $refund = PaymentRefund::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if (in_array($refund->status, ['succeeded', 'failed'], true)) {
                return $refund;
            }
            $metadata = $refund->metadata ?? [];
            $metadata['review_reason'] = $reason;
            $refund->forceFill(['status' => 'review_required', 'metadata' => $metadata])->save();

            return $refund->fresh();
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function applyStatus(string $publicId, string $providerStatus, ?string $providerRef, array $metadata = []): PaymentRefund
    {
        return DB::transaction(function () use ($publicId, $providerStatus, $providerRef, $metadata): PaymentRefund {
            $refund = PaymentRefund::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $normalized = match ($providerStatus) {
                'processed', 'success', 'succeeded' => 'succeeded',
                'failed' => 'failed',
                'needs-attention', 'needs_attention' => 'review_required',
                'pending', 'processing', 'queued', 'initiated' => 'provider_pending',
                default => 'review_required',
            };

            if ($refund->status === 'succeeded') {
                return $refund;
            }

            $combined = $refund->metadata ?? [];
            $combined = array_merge($combined, $metadata);
            $refund->forceFill([
                'status' => $normalized,
                'provider_status' => substr($providerStatus, 0, 40),
                'provider_refund_reference' => $providerRef ?? $refund->provider_refund_reference,
                'metadata' => $combined,
                'settled_at' => $normalized === 'succeeded' ? now() : $refund->settled_at,
            ])->save();

            if ($normalized === 'failed' && $refund->wallet_debit_transaction_id !== null) {
                $intent = PaymentIntent::query()->whereKey($refund->payment_intent_id)->firstOrFail();
                $this->ledger->post(
                    walletId: $intent->wallet_id,
                    type: 'credit',
                    amountMinor: $refund->amount_minor,
                    idempotencyKey: 'refund-failure-compensation:'.$refund->public_id,
                    reference: $intent->reference,
                    description: 'Restore wallet funds after confirmed refund failure',
                    metadata: ['payment_refund_id' => $refund->public_id, 'provider_status' => 'failed'],
                    correlationId: $refund->public_id,
                );
            }

            return $refund->fresh();
        }, attempts: 3);
    }
}
