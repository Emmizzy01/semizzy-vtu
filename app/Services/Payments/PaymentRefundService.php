<?php

namespace App\Services\Payments;

use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\PaymentIntent;
use App\Models\PaymentRefund;
use App\Models\User;
use App\Models\WalletAuditEvent;
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
            // Serialize a customer's idempotency-key checks across simultaneous requests.
            User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $intent = PaymentIntent::query()
                ->where('public_id', $intentPublicId)
                ->lockForUpdate()
                ->first();

            if ($intent === null || (int) $intent->wallet()->value('user_id') !== (int) $customer->id) {
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
                'status_changed_at' => now(),
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
                if ((int) $refund->requested_by_user_id === (int) $approver->id) {
                    throw new \Illuminate\Auth\Access\AuthorizationException('You cannot approve your own refund request.');
                }
                if (! in_array($refund->status, ['requested', 'blocked_insufficient_wallet_funds'], true)) {
                    throw new ConflictHttpException('Only requested or balance-blocked refunds can be approved.');
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
                    'status_changed_at' => now(),
                    'approved_at' => now(),
                ])->save();

                return $refund->fresh();
            }, attempts: 3);
        } catch (InsufficientWalletFunds $exception) {
            $blocked = PaymentRefund::query()->where('public_id', $publicId)->where('status', 'requested')->first();
            if ($blocked !== null) {
                $metadata = $blocked->metadata ?? [];
                $metadata['settlement_block'] = 'insufficient_available_balance';
                $blocked->forceFill([
                    'status' => 'blocked_insufficient_wallet_funds',
                    'status_changed_at' => $blocked->status === 'blocked_insufficient_wallet_funds' ? $blocked->status_changed_at : now(),
                    'metadata' => $metadata,
                ])->save();
            }

            $blockedRefund = PaymentRefund::query()->where('public_id', $publicId)->firstOrFail();
            $blockedIntent = $blockedRefund->paymentIntent()->firstOrFail();
            WalletAuditEvent::query()->create([
                'wallet_id' => $blockedIntent->wallet_id,
                'actor_user_id' => $approver->id,
                'event_type' => 'wallet.refund.reservation_rejected',
                'outcome' => 'rejected',
                'correlation_id' => $blockedRefund->public_id,
                'context' => [
                    'payment_refund_id' => $blockedRefund->public_id,
                    'amount_minor' => $blockedRefund->amount_minor,
                    'reason' => 'insufficient_available_balance',
                ],
                'created_at' => now(),
            ]);

            return $blockedRefund;
        }

        try {
            $intent = $refund->paymentIntent()->firstOrFail();
            $gatewayMode = $intent->metadata['gateway_mode'] ?? null;
            if (! in_array($gatewayMode, ['test', 'live'], true)) {
                throw new LogicException('Refund is blocked because the original payment has no recorded gateway mode.');
            }
            $providerData = $this->paystack->refund($intent->reference, $refund->amount_minor, $refund->reason, $gatewayMode);
            $providerStatus = strtolower((string) ($providerData['status'] ?? 'pending'));
            $providerRef = $providerData['refund_reference'] ?? $providerData['id'] ?? null;

            return $this->applyStatus(
                $refund->public_id,
                $providerStatus,
                is_scalar($providerRef) ? (string) $providerRef : null,
                ['provider_response_status' => $providerStatus],
            );
        } catch (\Throwable $exception) {
            // Network, transport, and provider exceptions can all happen after Paystack
            // accepted the request. Preserve the wallet reservation and require canonical
            // reconciliation; never resubmit an uncertain refund automatically.
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
    /**
     * Reconcile one stored refund against Paystack's canonical refund record.
     *
     * @return array{outcome:string,status:string}
     */
    public function reconcileProviderStatus(string $publicId): array
    {
        $refund = PaymentRefund::query()->where('public_id', $publicId)->firstOrFail();
        if (! in_array($refund->status, ['processing', 'provider_pending', 'review_required'], true)) {
            return ['outcome' => 'skipped', 'status' => $refund->status];
        }

        $providerRef = $refund->provider_refund_reference;
        if (! is_string($providerRef) || ! ctype_digit($providerRef)) {
            $review = $this->markReviewRequired($refund->public_id, 'missing_provider_refund_id_for_reconciliation');

            return [
                'outcome' => in_array($review->status, ['succeeded', 'failed'], true) ? 'skipped' : 'mismatch',
                'status' => $review->status,
            ];
        }

        $intent = $refund->paymentIntent()->firstOrFail();
        $gatewayMode = $intent->metadata['gateway_mode'] ?? null;
        if (! in_array($gatewayMode, ['test', 'live'], true)) {
            $review = $this->markReviewRequired($refund->public_id, 'missing_original_gateway_mode');

            return [
                'outcome' => in_array($review->status, ['succeeded', 'failed'], true) ? 'skipped' : 'mismatch',
                'status' => $review->status,
            ];
        }

        try {
            $provider = $this->paystack->fetchRefund($providerRef, $gatewayMode);
        } catch (\Throwable $exception) {
            // A failed lookup is not evidence that the provider refund failed.
            // Re-read state because another worker may have settled it while the
            // provider request was in flight; never report stale pending status.
            report($exception);
            // Lock and reread the refund so a concurrent settlement transaction
            // must commit before we choose which status to report.
            $current = DB::transaction(
                fn (): PaymentRefund => PaymentRefund::query()
                    ->whereKey($refund->id)
                    ->lockForUpdate()
                    ->firstOrFail(),
                attempts: 3,
            );

            if (in_array($current->status, ['succeeded', 'failed'], true)) {
                return ['outcome' => 'skipped', 'status' => $current->status];
            }

            return ['outcome' => 'unavailable', 'status' => $current->status];
        }

        $providerId = isset($provider['id']) && is_numeric($provider['id']) ? (string) $provider['id'] : null;
        $providerAmount = $provider['amount'] ?? null;
        $providerAmount = is_int($providerAmount)
            ? $providerAmount
            : (is_string($providerAmount) && ctype_digit($providerAmount) ? (int) $providerAmount : null);
        $providerCurrency = is_string($provider['currency'] ?? null) ? strtoupper($provider['currency']) : null;
        $providerTransaction = isset($provider['transaction']) && is_numeric($provider['transaction'])
            ? (string) $provider['transaction'] : null;

        if ($providerId !== $providerRef
            || $providerAmount !== $refund->amount_minor
            || $providerCurrency !== $intent->currency
            || $intent->provider_transaction_id === null
            || $providerTransaction !== (string) $intent->provider_transaction_id) {
            $review = $this->markReviewRequired($refund->public_id, 'provider_reconciliation_data_mismatch');

            return [
                'outcome' => in_array($review->status, ['succeeded', 'failed'], true) ? 'skipped' : 'mismatch',
                'status' => $review->status,
            ];
        }

        $providerStatus = is_string($provider['status'] ?? null)
            ? strtolower($provider['status']) : 'unknown';
        $updated = $this->applyStatus(
            $refund->public_id,
            $providerStatus,
            $providerRef,
            ['last_reconciled_at' => now()->toISOString(), 'reconciliation_source' => 'paystack_fetch_refund'],
            allowReviewResolution: true,
        );

        return ['outcome' => 'reconciled', 'status' => $updated->status];
    }

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
        // An explicit provider refund ID is authoritative. Never fall back to
        // transaction-level matching when that ID was supplied but did not match:
        // doing so could apply an unrelated refund event to another pending refund.
        if ($providerRef === null && $matches->isEmpty() && is_string($transactionRef) && $transactionRef !== '') {
            $intent = PaymentIntent::query()
                ->where('provider', 'paystack')
                ->where('reference', $transactionRef)
                ->first();

            if ($intent !== null) {
                $matches = PaymentRefund::query()
                    ->where('payment_intent_id', $intent->id)
                    ->whereIn('status', ['processing', 'provider_pending', 'review_required'])
                    ->limit(2)
                    ->get();
            }
        }
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

        $refund = $matches->first();
        $intent = $refund->paymentIntent()->firstOrFail();
        $observedAmount = $data['amount'] ?? null;
        $observedAmount = is_int($observedAmount)
            ? $observedAmount
            : (is_string($observedAmount) && ctype_digit($observedAmount) ? (int) $observedAmount : null);
        $observedCurrency = is_string($data['currency'] ?? null) ? strtoupper($data['currency']) : null;
        $referenceMismatch = is_string($transactionRef) && $transactionRef !== '' && $transactionRef !== $intent->reference;

        if ($observedAmount !== $refund->amount_minor
            || $observedCurrency !== $intent->currency
            || $referenceMismatch) {
            $this->markReviewRequired($refund->public_id, 'provider_refund_payload_mismatch');

            return true;
        }

        // A refund already escalated for review must not be downgraded by a later
        // webhook, even if that event is otherwise well-formed. Resolve review_required
        // only through canonical provider reconciliation or an explicit operator workflow.
        if ($refund->status === 'review_required') {
            return true;
        }

        $this->applyStatus(
            $refund->public_id,
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
            if ($refund->status === 'review_required' && ($metadata['review_reason'] ?? null) === $reason) {
                return $refund;
            }
            $metadata['review_reason'] = $reason;
            $refund->forceFill([
                'status' => 'review_required',
                'status_changed_at' => $refund->status === 'review_required' ? $refund->status_changed_at : now(),
                'metadata' => $metadata,
            ])->save();

            return $refund->fresh();
        }, attempts: 3);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function applyStatus(
        string $publicId,
        string $providerStatus,
        ?string $providerRef,
        array $metadata = [],
        bool $allowReviewResolution = false,
    ): PaymentRefund {
        return DB::transaction(function () use ($publicId, $providerStatus, $providerRef, $metadata, $allowReviewResolution): PaymentRefund {
            $refund = PaymentRefund::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $normalized = match ($providerStatus) {
                'processed', 'success', 'succeeded' => 'succeeded',
                'failed' => 'failed',
                'needs-attention', 'needs_attention' => 'review_required',
                'pending', 'processing', 'queued', 'initiated' => 'provider_pending',
                default => 'review_required',
            };

            if (in_array($refund->status, ['succeeded', 'failed'], true)) {
                return $refund;
            }

            // Enforce review_required under the same row lock as status changes. The
            // earlier webhook check alone cannot prevent a concurrent stale event from
            // downgrading a review decision after its initial read.
            if ($refund->status === 'review_required' && ! $allowReviewResolution && $normalized !== 'review_required') {
                return $refund;
            }

            $combined = $refund->metadata ?? [];
            $combined = array_merge($combined, $metadata);
            $refund->forceFill([
                'status' => $normalized,
                'status_changed_at' => $normalized === $refund->status ? ($refund->status_changed_at ?? now()) : now(),
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
                    actorUserId: $refund->approved_by_user_id,
                    metadata: ['payment_refund_id' => $refund->public_id, 'provider_status' => 'failed'],
                    correlationId: $refund->public_id,
                    isFinancialRecovery: true,
                );
            }

            return $refund->fresh();
        }, attempts: 3);
    }
}
