<?php

namespace App\Services\Education;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\EducationOrder;
use App\Models\EducationProviderAttempt;
use App\Models\EducationServiceProduct;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Security\UserLevelPolicyService;
use App\Services\Addons\EducationPurchaseAdmissionPolicy;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EducationOrderCoordinator
{
    public function __construct(
        private readonly EducationPurchaseService $preflight,
        private readonly EducationProviderRegistry $providers,
        private readonly WalletAccountService $walletAccounts,
        private readonly WalletLedgerService $ledger,
        private readonly UserLevelPolicyService $levelPolicies,
    ) {
    }

    /**
     * Creates a single debited order and durable attempt before provider submission.
     * Unknown outcomes stay debited and are reconciled; they are never blindly retried.
     *
     * @param array<string, scalar|null> $candidate
     */
    public function purchase(User $user, string $productCode, string $idempotencyKey, array $candidate): EducationOrder
    {
        $requestHash = hash('sha256', json_encode([
            'user_id' => $user->getKey(),
            'product_code' => $productCode,
            'candidate' => $candidate,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $existing = EducationOrder::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            // Existing idempotent orders remain readable for recovery even when
            // new-purchase admission has since been disabled.
            return $this->returnExisting($existing, $requestHash);
        }

        $product = $this->preflight->preflight($user, $productCode, $idempotencyKey, $candidate);
        $provider = $this->providers->forProduct($product);
        $wallet = $this->walletAccounts->openForUser($user, 'NGN');

        try {
            $order = DB::transaction(function () use ($user, $product, $wallet, $idempotencyKey, $requestHash, $candidate): EducationOrder {
                $existing = EducationOrder::query()
                    ->where('user_id', $user->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->returnExisting($existing, $requestHash);
                }

                // Admission can change after preflight (which may contact the
                // provider for candidate validation). Recheck inside the reservation
                // transaction before any order, debit, or provider-attempt record exists.
                app(EducationPurchaseAdmissionPolicy::class)->assertAvailable();

                $lockedProduct = EducationServiceProduct::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
                if (! $lockedProduct->is_active || ! $lockedProduct->purchase_approved || $lockedProduct->currency !== 'NGN'
                    || (int) $lockedProduct->price_minor < 1
                    || (int) $lockedProduct->price_minor !== (int) $product->price_minor
                    || (string) $lockedProduct->provider !== (string) $product->provider
                    || (string) $lockedProduct->provider_product_code !== (string) $product->provider_product_code) {
                    throw new \LogicException('Education product price or provider mapping changed before reservation; retry after reviewing the catalogue.');
                }

                $lockedWallet = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
                $policyRejection = $this->levelPolicies->rejectionReasonForAmount(
                    $user,
                    (int) $lockedWallet->getKey(),
                    (int) $lockedProduct->price_minor,
                );
                if ($policyRejection !== null) {
                    throw new \LogicException($policyRejection);
                }

                $order = EducationOrder::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'education_service_product_id' => $lockedProduct->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'provider' => (string) $lockedProduct->provider,
                    'status' => 'processing',
                    'amount_minor' => (int) $lockedProduct->price_minor,
                    'currency' => 'NGN',
                    'candidate_details' => [],
                    'encrypted_candidate_details' => $candidate,
                    'dispatch_started_at' => now(),
                ]);

                $transaction = $this->ledger->post(
                    walletId: (int) $wallet->getKey(),
                    type: 'debit',
                    amountMinor: (int) $lockedProduct->price_minor,
                    idempotencyKey: 'education-purchase:'.$order->public_id,
                    reference: $order->public_id,
                    description: 'Education purchase: '.$lockedProduct->name,
                    actorUserId: (int) $user->getKey(),
                    metadata: [
                        'education_order_id' => $order->public_id,
                        'product_code' => $lockedProduct->code,
                    ],
                    correlationId: $order->public_id,
                );

                $order->forceFill(['wallet_transaction_id' => $transaction->getKey()])->save();
                EducationProviderAttempt::query()->create([
                    'education_order_id' => $order->getKey(),
                    'attempt_number' => 1,
                    'provider' => (string) $lockedProduct->provider,
                    'outcome' => 'started',
                    'request_fingerprint' => hash('sha256', implode('|', [
                        $order->public_id, $lockedProduct->provider, $lockedProduct->code,
                    ])),
                    'started_at' => now(),
                ]);

                return $order;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = EducationOrder::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $this->returnExisting($existing, $requestHash);
            }

            throw $exception;
        }

        // A competing request may have returned an already-reserved order. Only the
        // request that actually inserted it may perform the first provider submission.
        if (! $order->wasRecentlyCreated || $order->status !== 'processing' || $order->dispatch_started_at === null) {
            return $order;
        }

        try {
            // The addon may have been disabled after reservation. Do not send a
            // new provider request in that case; retain the debit/order for lookup
            // and reconciliation instead of refunding an uncertain operation.
            $this->assertPurchaseAdmission();
            $result = $provider->submit($order->public_id, $product, $candidate);
        } catch (\LogicException $exception) {
            if (str_contains($exception->getMessage(), 'Education purchases are disabled at the addon execution boundary:')) {
                return $this->recordOutcome($order, 'unknown', null, 'addon_admission_closed_before_submission');
            }

            $this->recordOutcome($order, 'unknown', null, 'provider_submission_configuration_unknown');
            return $order->fresh();
        } catch (Throwable) {
            // Provider outcome is ambiguous. Keep the debit and durable attempt for lookup.
            $this->recordOutcome($order, 'unknown', null, 'submission_outcome_unknown');
            return $order->fresh();
        }

        $status = $result['status'] ?? 'unknown';
        if (! in_array($status, ['success', 'failed', 'pending', 'unknown'], true)) {
            $status = 'unknown';
        }

        return $this->recordOutcome(
            $order,
            $status,
            isset($result['provider_reference']) ? (string) $result['provider_reference'] : null,
            isset($result['message_code']) ? (string) $result['message_code'] : null,
            is_array($result['delivery_payload'] ?? null) ? $result['delivery_payload'] : null,
        );
    }

    /**
     * Reconcile an ambiguous order using the exact provider persisted on the order.
     * This method never submits or reroutes a purchase.
     */
    public function reconcile(EducationOrder $order): EducationOrder
    {
        if (in_array($order->status, ['successful', 'failed', 'cancelled'], true)) {
            return $order;
        }

        // Do not race the original provider submission with an immediate lookup.
        // A worker can observe the reserved order while the HTTP submission is still
        // in flight; a transient "not found" response must not be treated as a final
        // failure and trigger a refund. Stale "started" attempts are still recoverable
        // after a bounded grace period, while pending/unknown attempts can be looked up.
        $attempt = EducationProviderAttempt::query()
            ->where('education_order_id', $order->getKey())
            ->where('attempt_number', 1)
            ->first();

        if ($attempt === null) {
            return $order->fresh();
        }

        $graceSeconds = max(30, (int) config('services.education.reconciliation_grace_seconds', 120));
        if ($attempt->outcome === 'started'
            && $attempt->started_at !== null
            && $attempt->started_at->gt(now()->subSeconds($graceSeconds))) {
            return $order->fresh();
        }

        $order->loadMissing('product');
        $provider = $this->providers->forProvider((string) $attempt->provider);

        try {
            $result = $provider->lookup($order->public_id, $order->product);
        } catch (Throwable) {
            return $order->fresh();
        }

        $status = $result['status'] ?? 'unknown';
        if (! in_array($status, ['success', 'failed', 'pending', 'unknown'], true)) {
            $status = 'unknown';
        }

        return $this->recordOutcome(
            $order,
            $status,
            isset($result['provider_reference']) ? (string) $result['provider_reference'] : null,
            isset($result['message_code']) ? (string) $result['message_code'] : null,
            is_array($result['delivery_payload'] ?? null) ? $result['delivery_payload'] : null,
        );
    }

    private function returnExisting(EducationOrder $order, string $requestHash): EducationOrder
    {
        if (! hash_equals((string) $order->request_hash, $requestHash)) {
            throw new IdempotencyConflict('This education idempotency key was already used for a different request.');
        }

        // An existing processing/unknown order is returned for reconciliation; never resubmit here.
        return $order;
    }

    private function recordOutcome(EducationOrder $order, string $status, ?string $providerReference, ?string $messageCode, ?array $deliveryPayload = null): EducationOrder
    {
        return DB::transaction(function () use ($order, $status, $providerReference, $messageCode, $deliveryPayload): EducationOrder {
            $locked = EducationOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, ['successful', 'failed', 'cancelled'], true)) {
                $terminalStatus = match ($locked->status) {
                    'successful' => 'success',
                    'failed' => 'failed',
                    'cancelled' => 'cancelled',
                    default => $locked->status,
                };
                if (in_array($status, ['success', 'failed'], true) && $status !== $terminalStatus) {
                    // Only a definitive success/failure disagreement is a terminal
                    // conflict. Pending or unknown lookups are weaker observations and
                    // must not repeatedly replace the conflict evidence on a completed order.
                    // Never issue a second refund or rewrite a completed order from a stale lookup.
                    $safeResponse = is_array($locked->safe_provider_response) ? $locked->safe_provider_response : [];
                    $conflict = [
                        'stored_status' => $terminalStatus,
                        'observed_status' => $status,
                        'observed_at' => now()->toIso8601String(),
                        'message_code' => $messageCode,
                    ];
                    $history = is_array($safeResponse['terminal_status_conflicts'] ?? null)
                        ? $safeResponse['terminal_status_conflicts']
                        : [];

                    // Keep a bounded audit trail rather than letting a later
                    // contradictory lookup erase an earlier provider disagreement.
                    $lastConflict = $history === [] ? null : $history[array_key_last($history)];
                    if (! is_array($lastConflict)
                        || ($lastConflict['stored_status'] ?? null) !== $terminalStatus
                        || ($lastConflict['observed_status'] ?? null) !== $status
                        || ($lastConflict['message_code'] ?? null) !== $messageCode) {
                        $history[] = $conflict;
                    }
                    $safeResponse['terminal_status_conflicts'] = array_slice($history, -10);
                    $safeResponse['terminal_status_conflict'] = $conflict;
                    $locked->forceFill(['safe_provider_response' => $safeResponse])->save();
                }

                return $locked->fresh();
            }

            // A concurrent lookup can return an indeterminate result after another
            // worker has already recorded a provider-confirmed pending state. Keep
            // the stronger observation and its attempt metadata rather than regressing
            // the order to unknown; a later canonical lookup can still resolve it.
            if ($locked->status === 'pending' && $status === 'unknown') {
                return $locked;
            }

            $attempt = EducationProviderAttempt::query()
                ->where('education_order_id', $locked->getKey())
                ->where('attempt_number', 1)
                ->lockForUpdate()
                ->firstOrFail();

            $attempt->forceFill([
                'outcome' => $status,
                'provider_reference' => $providerReference,
                'message_code' => $messageCode,
                'finished_at' => in_array($status, ['success', 'failed'], true) ? now() : null,
            ])->save();

            $locked->forceFill([
                'provider_reference' => $providerReference ?? $locked->provider_reference,
                'safe_provider_response' => [
                    'status' => $status,
                    'provider_reference' => $providerReference,
                    'message_code' => $messageCode,
                ],
            ]);

            if ($status === 'success') {
                if ($deliveryPayload !== null && $deliveryPayload !== []) {
                    // Model encrypted:array cast encrypts the delivery payload at rest.
                    // Never copy PINs/tokens into safe_provider_response or logs.
                    $locked->provider_delivery_payload = $deliveryPayload;
                }
                $locked->status = 'successful';
                $locked->completed_at = now();
            } elseif ($status === 'failed') {
                // Only a provider-confirmed definitive failure triggers a refund.
                $this->ledger->post(
                    walletId: (int) $locked->wallet_id,
                    type: 'credit',
                    amountMinor: (int) $locked->amount_minor,
                    idempotencyKey: 'education-refund:'.$locked->public_id,
                    reference: $locked->public_id,
                    description: 'Refund for failed education purchase',
                    actorUserId: (int) $locked->user_id,
                    metadata: ['education_order_id' => $locked->public_id, 'reason' => 'provider_definitive_failure'],
                    correlationId: $locked->public_id,
                    isFinancialRecovery: true,
                );
                $locked->status = 'failed';
                $locked->failure_code = $messageCode ?? 'provider_failed';
                $locked->completed_at = now();
            } else {
                $locked->status = $status === 'pending' ? 'pending' : 'unknown';
            }

            $locked->save();

            return $locked->fresh();
        }, attempts: 3);
    }
}
