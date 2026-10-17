<?php

namespace App\Services\Vtu;

use App\Enums\VtuOrderStatus;
use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
use App\Models\VtuProduct;
use App\Models\PlatformFeatureControl;
use App\Models\Wallet;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use App\Services\Security\UserLevelPolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class VtuOrderService
{
    public function __construct(
        private readonly WalletAccountService $walletAccounts,
        private readonly WalletLedgerService $ledger,
        private readonly VtuProvider $provider,
        private readonly UserLevelPolicyService $levelPolicies,
    ) {
    }

    /**
     * Create and debit an order once, then submit it outside the database
     * transaction. A repeat request returns the existing order and never
     * submits a second provider purchase.
     */
    public function purchase(
        User $user,
        string $productCode,
        string $recipient,
        string $idempotencyKey,
        array $serviceDetails = [],
        ?int $amountMinor = null,
    ): VtuOrder {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Idempotency key must contain 1 to 128 characters.');
        }

        $recipient = preg_replace('/[\s-]+/', '', trim($recipient)) ?? '';
        if (! preg_match('/^[a-zA-Z0-9+]{5,40}$/', $recipient)) {
            throw new InvalidArgumentException('Enter a valid phone number, meter number, or smartcard number.');
        }

        /** @var VtuProduct $product */
        $product = VtuProduct::query()
            ->where('code', $productCode)
            ->whereIn('service_type', ['airtime', 'data', 'electricity-bill', 'tv-subscription'])
            ->where('currency', 'NGN')
            ->firstOrFail();

        if (! in_array($product->service_type, ['airtime', 'data'], true)) {
            if (($product->metadata['purchase_supported'] ?? null) !== true) {
                throw new InvalidArgumentException('This service is not yet enabled for customer purchases.');
            }
            $this->assertServiceFeatureEnabled($product);
            $contactPhone = preg_replace('/[\s-]+/', '', (string) ($serviceDetails['contact_phone'] ?? '')) ?? '';
            if (! preg_match('/^\+?[0-9]{10,15}$/', $contactPhone)) {
                throw new InvalidArgumentException('Enter a valid contact phone number for the provider transaction.');
            }
            $serviceDetails['contact_phone'] = $contactPhone;
            if ($product->service_type === 'electricity-bill') {
                if (! in_array($serviceDetails['meter_type'] ?? null, ['prepaid', 'postpaid'], true)) {
                    throw new InvalidArgumentException('Select prepaid or postpaid for the electricity meter.');
                }
                if ($amountMinor === null || $amountMinor < 100) {
                    throw new InvalidArgumentException('Enter a valid electricity purchase amount.');
                }
                $minimum = (int) round((float) ($product->metadata['provider_minimum_amount'] ?? 1) * 100);
                $maximum = (int) round((float) ($product->metadata['provider_maximum_amount'] ?? 1000000) * 100);
                if ($amountMinor < $minimum || $amountMinor > $maximum) {
                    throw new InvalidArgumentException('The electricity amount is outside the provider limits.');
                }
            } elseif ($product->service_type === 'tv-subscription') {
                // Bouquet changes are supported by the current checkout flow.
                // Renewal needs the provider-verified Renewal_Amount and remains
                // blocked until that value is carried through verification safely.
                $subscriptionType = $serviceDetails['subscription_type'] ?? 'change';
                if ($subscriptionType !== 'change') {
                    throw new InvalidArgumentException('Only verified TV bouquet changes are available right now. Renewal is not enabled.');
                }
                $serviceDetails['subscription_type'] = 'change';
                $amountMinor = (int) $product->price_minor;
                if ($amountMinor < 1) {
                    throw new InvalidArgumentException('This TV subscription has no valid provider price.');
                }
            }
        } else {
            if (! preg_match('/^\+?[0-9]{10,15}$/', $recipient)) {
                throw new InvalidArgumentException('Recipient must be a valid phone number.');
            }
            if ($product->service_type === 'airtime') {
                $amountMinor ??= (int) $product->price_minor;
                if ($amountMinor < 100 || $amountMinor > 100000000 || $amountMinor % 100 !== 0) {
                    throw new InvalidArgumentException('Airtime amount must be a whole-naira amount between ₦1 and ₦1,000,000.');
                }
            } else {
                $amountMinor = (int) $product->price_minor;
            }
        }

        $requestHash = hash('sha256', json_encode(
            $product->service_type === 'airtime'
                ? [
                    'user_id' => $user->getKey(),
                    'product_code' => $product->code,
                    'recipient' => $recipient,
                    'amount_minor' => $amountMinor,
                ]
                : ($product->service_type === 'data' ? [
                    'user_id' => $user->getKey(),
                    'product_code' => $product->code,
                    'recipient' => $recipient,
                ] : [
                    'user_id' => $user->getKey(),
                    'product_code' => $product->code,
                    'recipient' => $recipient,
                    'service_details' => $serviceDetails,
                    'amount_minor' => $amountMinor,
                ]),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));

        $existing = VtuOrder::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->returnExisting($existing, $requestHash);
        }

        if (! $product->is_active) {
            throw new InvalidArgumentException('This product is no longer available.');
        }

        $this->provider->assertConfigured();
        $this->provider->assertProductConfigured($product);
        $this->verifyBillerBeforePurchase($product, $recipient, $serviceDetails);

        $wallet = Wallet::query()
            ->where('user_id', $user->getKey())
            ->where('currency', 'NGN')
            ->first();

        if ($wallet === null) {
            $wallet = $this->walletAccounts->openForUser($user, 'NGN');
        }

        try {
            $order = DB::transaction(function () use ($user, $wallet, $product, $recipient, $idempotencyKey, $requestHash, $serviceDetails, $amountMinor): VtuOrder {
                $duplicate = VtuOrder::query()
                    ->where('user_id', $user->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($duplicate !== null) {
                    return $this->returnExisting($duplicate, $requestHash);
                }

                // Re-read the catalogue row under lock: an admin may have
                // repriced or disabled this product after the initial preflight.
                /** @var VtuProduct $lockedProduct */
                $lockedProduct = VtuProduct::query()
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $lockedProduct->is_active) {
                    throw new InvalidArgumentException('This product is no longer available.');
                }

                if (! in_array($lockedProduct->service_type, ['airtime', 'data'], true)) {
                    $this->assertServiceFeatureEnabled($lockedProduct);
                }
                $this->provider->assertProductConfigured($lockedProduct);
                $lockedAmountMinor = in_array($lockedProduct->service_type, ['electricity-bill', 'airtime'], true)
                    ? $amountMinor
                    : (int) $lockedProduct->price_minor;
                if (! is_int($lockedAmountMinor) || $lockedAmountMinor < 1) {
                    throw new InvalidArgumentException('The locked product price or purchase amount is invalid.');
                }
                // Re-check provider-specific electricity limits against the locked
                // catalogue row; admin changes between preflight and debit must not
                // allow an amount outside the currently approved range.
                if ($lockedProduct->service_type === 'airtime'
                    && ($lockedAmountMinor < 100 || $lockedAmountMinor > 100000000 || $lockedAmountMinor % 100 !== 0)) {
                    throw new InvalidArgumentException('Airtime amount must be a whole-naira amount between ₦1 and ₦1,000,000.');
                }
                if ($lockedProduct->service_type === 'electricity-bill') {
                    $minimumMinor = (int) round((float) ($lockedProduct->metadata['provider_minimum_amount'] ?? 1) * 100);
                    $maximumMinor = (int) round((float) ($lockedProduct->metadata['provider_maximum_amount'] ?? 1000000) * 100);
                    if ($lockedAmountMinor < $minimumMinor || $lockedAmountMinor > $maximumMinor) {
                        throw new InvalidArgumentException('The electricity amount is outside the provider limits.');
                    }
                }
                $lockedWallet = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
                $policyRejection = $this->levelPolicies->rejectionReason($user, $lockedProduct, (int) $lockedWallet->getKey(), $lockedAmountMinor);
                if ($policyRejection !== null) throw new InvalidArgumentException($policyRejection);

                $order = VtuOrder::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'vtu_product_id' => $lockedProduct->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'service_type' => $lockedProduct->service_type,
                    'network' => $lockedProduct->network,
                    'product_code' => $lockedProduct->code,
                    'recipient' => $recipient,
                    'amount_minor' => $lockedAmountMinor,
                    'service_details' => $serviceDetails,
                    'currency' => $lockedProduct->currency,
                    'status' => VtuOrderStatus::Pending->value,
                    'provider' => $this->provider instanceof MultiProviderVtuRouter
                        ? $this->provider->providerNameForProduct($lockedProduct)
                        : (app(\App\Services\IntegrationSettings::class)->get('vtu.driver', config('services.vtu.driver')) === 'vtpass'
                            ? 'vtpass'
                            : (string) config('services.vtu.name', 'configured_vtu')),
                ]);

                $transaction = $this->ledger->post(
                    walletId: (int) $wallet->getKey(),
                    type: 'debit',
                    amountMinor: $lockedAmountMinor,
                    idempotencyKey: 'vtu-purchase:'.$order->public_id,
                    reference: $order->public_id,
                    description: 'VTU purchase: '.$lockedProduct->name,
                    actorUserId: (int) $user->getKey(),
                    metadata: [
                        'vtu_order_id' => $order->public_id,
                        'product_code' => $lockedProduct->code,
                        'recipient' => $recipient,
                    ],
                    correlationId: $order->public_id,
                );

                $order->forceFill([
                    'wallet_transaction_id' => $transaction->getKey(),
                    // Persist the dispatch marker and attempt record atomically
                    // with the debit. A crash cannot leave a debited order marked
                    // dispatched without a durable provider-attempt row.
                    'status' => VtuOrderStatus::Processing->value,
                    'dispatch_started_at' => now(),
                ])->save();

                VtuProviderAttempt::query()->create([
                    'vtu_order_id' => $order->getKey(),
                    'attempt_number' => 1,
                    'provider' => (string) $order->provider,
                    'outcome' => 'started',
                    'request_fingerprint' => hash('sha256', implode('|', [
                        $order->public_id, $order->provider, $order->product_code,
                        $order->recipient, (string) $order->amount_minor,
                    ])),
                    'started_at' => now(),
                ]);

                return $order;
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = VtuOrder::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->returnExisting($existing, $requestHash);
        }

        // A duplicate discovered inside the transaction is already in progress
        // or terminal. Do not call the provider a second time.
        if ($order->wasRecentlyCreated === false) {
            return $this->returnExisting($order, $requestHash);
        }

        // The attempt row was committed atomically with the order debit and
        // dispatch marker. Reload it before updating the provider outcome.
        $attempt = VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->where('attempt_number', 1)
            ->firstOrFail();

        try {
            $result = $this->provider->submit($order);
        } catch (ConnectionException|LogicException $exception) {
            report($exception);
            $attempt->forceFill([
                'outcome' => 'unknown',
                'failure_code' => 'provider_result_unknown',
                'definitive_failure' => false,
                'finished_at' => now(),
            ])->save();
            $updated = $this->markAwaitingReconciliation($order, 'provider_result_unknown');
            $updated->setAttribute('_created_for_request', true);

            return $updated;
        }

        $providerStatus = $result['status'] ?? 'unknown';
        $definitiveFailure = app(ProviderFailoverPolicy::class)
            ->mayFailover((string) $order->provider, $result);
        $attempt->forceFill([
            'outcome' => in_array($providerStatus, ['success', 'failed', 'pending'], true) ? $providerStatus : 'unknown',
            'provider_reference' => is_string($result['provider_reference'] ?? null)
                ? substr($result['provider_reference'], 0, 191) : null,
            'failure_code' => $providerStatus === 'failed'
                ? (is_string($result['message_code'] ?? null) ? substr($result['message_code'], 0, 100) : 'provider_failed')
                : null,
            'definitive_failure' => $definitiveFailure,
            'finished_at' => now(),
        ])->save();

        // Failover is deliberately not inferred from status=failed alone. Until
        // a provider adapter supplies a contract-tested definitive_failure=true,
        // ambiguous and generic failures remain on the original provider for
        // reconciliation; they must not cause a second purchase submission.
        $updated = $this->applyProviderResult($order, $result);
        $updated->setAttribute('_created_for_request', true);

        return $updated;
    }

    /** Fail closed until electricity/TV workflows are explicitly implemented and enabled. */
    private function assertServiceFeatureEnabled(VtuProduct $product): void
    {
        $featureKey = match ($product->service_type) {
            'electricity-bill' => 'service.electricity',
            'tv-subscription' => 'service.tv',
            default => null,
        };
        if ($featureKey === null) {
            return;
        }

        $feature = PlatformFeatureControl::query()->where('feature_key', $featureKey)->first();
        if ($feature === null || ! $feature->is_enabled || ! $feature->is_implemented) {
            throw new InvalidArgumentException('This service is not yet enabled for customer purchases.');
        }
    }

    /** Re-verify biller details immediately before debiting the wallet. */
    private function verifyBillerBeforePurchase(VtuProduct $product, string $recipient, array $details): void
    {
        if (in_array($product->service_type, ['airtime', 'data'], true)) {
            return;
        }

        if ($this->provider instanceof MultiProviderVtuRouter) {
            $result = $product->service_type === 'electricity-bill'
                ? $this->provider->verifyElectricityMeter($product, $recipient, (string) ($details['meter_type'] ?? ''))
                : $this->provider->verifyTvSmartcard($product, $recipient);
        } elseif ($this->provider instanceof VtpassProviderClient) {
            $result = $product->service_type === 'electricity-bill'
                ? $this->provider->verifyElectricityMeter($product, $recipient, (string) ($details['meter_type'] ?? ''))
                : $this->provider->verifyTvSmartcard($product, $recipient);
        } else {
            throw new LogicException('Electricity and TV purchases require the VTpass verification adapter.');
        }

        if (($result['status'] ?? null) !== 'verified') {
            throw new InvalidArgumentException('The provider could not confirm this meter or smartcard. No purchase was created.');
        }
    }

    public function reconcile(VtuOrder $order): VtuOrder
    {
        if (! in_array($order->status, [
            VtuOrderStatus::Processing->value,
            VtuOrderStatus::AwaitingReconciliation->value,
        ], true) || $order->dispatch_started_at === null) {
            return $order;
        }

        try {
            $result = $this->provider->lookup($order);
        } catch (ConnectionException|LogicException $exception) {
            report($exception);
            return $order->fresh();
        }

        // Persist the lookup result against the original dispatch attempt.
        // Locking the attempt and preserving its first terminal outcome prevents
        // competing reconcilers from rewriting a recorded success as a failure.
        $this->recordReconciledAttemptOutcome($order, $result);

        // A reconciliation lookup that still reports pending means the original
        // dispatch outcome remains ambiguous. Keep it out of the normal processing
        // queue state without refunding or resubmitting the purchase.
        if (($result['status'] ?? null) === 'pending') {
            return $this->markAwaitingReconciliation($order, 'provider_still_pending', $result);
        }

        return $this->applyProviderResult($order, $result);
    }

    /**
     * Update the durable history for the original submission after a lookup.
     * This does not create a new purchase attempt: lookups are recovery work,
     * not permission to resubmit to this or another provider.
     *
     * @param array<string, mixed> $result
     */
    private function recordReconciledAttemptOutcome(VtuOrder $order, array $result): void
    {
        DB::transaction(function () use ($order, $result): void {
            /** @var VtuOrder $lockedOrder */
            $lockedOrder = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $attempt = VtuProviderAttempt::query()
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->where('provider', $lockedOrder->provider)
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($attempt === null || in_array($attempt->outcome, ['success', 'failed'], true)) {
                return;
            }

            $status = $result['status'] ?? 'unknown';
            $knownStatus = is_string($status) && in_array($status, ['success', 'failed', 'pending'], true);
            $definitiveFailure = $knownStatus
                && app(ProviderFailoverPolicy::class)->mayFailover((string) $attempt->provider, $result);

            $attempt->forceFill([
                'outcome' => $knownStatus ? $status : 'unknown',
                'provider_reference' => is_string($result['provider_reference'] ?? null)
                    ? substr($result['provider_reference'], 0, 191)
                    : $attempt->provider_reference,
                'failure_code' => $status === 'failed'
                    ? (is_string($result['message_code'] ?? null) ? substr($result['message_code'], 0, 100) : 'provider_failed')
                    : null,
                'definitive_failure' => $definitiveFailure,
                'finished_at' => now(),
            ])->save();
        }, attempts: 3);
    }

    /**
     * Apply a verified provider webhook only when it matches the recorded owner
     * and provider reference. Terminal-state and ledger protections stay centralized.
     *
     * @param array<string, mixed> $result
     */
    public function applyVerifiedWebhookResult(VtuOrder $order, string $provider, string $providerReference, array $result): VtuOrder
    {
        if ($order->provider !== $provider
            || ! is_string($order->provider_reference)
            || ! hash_equals($order->provider_reference, $providerReference)) {
            throw new LogicException('Verified webhook does not match the recorded provider transaction.');
        }

        return $this->applyProviderResult($order, $result);
    }

    private function returnExisting(VtuOrder $order, string $requestHash): VtuOrder
    {
        if (! hash_equals($order->request_hash, $requestHash)) {
            throw new ConflictHttpException('This idempotency key was already used for a different purchase.');
        }

        return $order;
    }

    /** @param array<string, mixed> $result */
    private function applyProviderResult(VtuOrder $order, array $result): VtuOrder
    {
        $providerStatus = $result['status'] ?? null;

        // Defense in depth: never interpret an unrecognized status as a
        // definitive failure, because that would incorrectly refund a possibly
        // fulfilled order. Only explicit allowlisted states may be applied.
        if (! is_string($providerStatus)
            || ! in_array($providerStatus, ['success', 'failed', 'pending'], true)) {
            return $this->markAwaitingReconciliation($order, 'provider_result_unknown', $result);
        }

        // A plain "failed" label is not enough to refund: only a provider
        // whose definitive non-delivery contract is explicitly allowlisted,
        // and whose response carries the strict boolean signal, is terminal.
        // This also applies to reconciliation results and verified webhooks.
        if ($providerStatus === 'failed'
            && ! app(ProviderFailoverPolicy::class)->mayFailover((string) $order->provider, $result)) {
            return $this->markAwaitingReconciliation($order, 'provider_failure_not_verified', $result);
        }

        if ($providerStatus === 'pending') {
            // Reconciliation may race with a terminal result. Lock and re-read
            // the row so a late "pending" response can never undo success/failure.
            $updated = DB::transaction(function () use ($order, $result): VtuOrder {
                /** @var VtuOrder $locked */
                $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, [
                    VtuOrderStatus::Successful->value,
                    VtuOrderStatus::Failed->value,
                    VtuOrderStatus::Cancelled->value,
                ], true)) {
                    return $locked;
                }

                // A pending lookup must not erase the ambiguous-submission marker.
                $status = $locked->status === VtuOrderStatus::AwaitingReconciliation->value
                    ? VtuOrderStatus::AwaitingReconciliation->value
                    : VtuOrderStatus::Processing->value;

                $locked->forceFill([
                    'status' => $status,
                    'provider_reference' => $result['provider_reference'] ?? $locked->provider_reference,
                    'safe_provider_response' => $this->safeResponse($result),
                ])->save();

                return $locked->fresh();
            }, attempts: 3);

            $updated->wasRecentlyCreated = $order->wasRecentlyCreated;

            return $updated;
        }

        $updated = DB::transaction(function () use ($order, $providerStatus, $result): VtuOrder {
            /** @var VtuOrder $locked */
            $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true)) {
                return $locked;
            }

            $next = $providerStatus === 'success'
                ? VtuOrderStatus::Successful
                : VtuOrderStatus::Failed;
            $current = VtuOrderStatus::from($locked->status);

            if (! $current->canTransitionTo($next)) {
                throw new LogicException('The VTU order status transition is not permitted.');
            }

            if ($providerStatus === 'failed') {
                // Only a definitive provider failure is refundable. An ambiguous
                // timeout must remain debited until provider state is reconciled.
                $this->ledger->post(
                    walletId: (int) $locked->wallet_id,
                    type: 'credit',
                    amountMinor: (int) $locked->amount_minor,
                    idempotencyKey: 'vtu-refund:'.$locked->public_id,
                    reference: $locked->public_id,
                    description: 'Refund for failed VTU purchase',
                    actorUserId: (int) $locked->user_id,
                    metadata: [
                        'vtu_order_id' => $locked->public_id,
                        'reason' => 'provider_definitive_failure',
                    ],
                    correlationId: $locked->public_id,
                );
            }

            $locked->forceFill([
                'status' => $next->value,
                'provider_reference' => $result['provider_reference'] ?? $locked->provider_reference,
                'failure_code' => $providerStatus === 'failed'
                    ? ($result['message_code'] ?? 'provider_failed')
                    : null,
                'safe_provider_response' => $this->safeResponse($result),
                'completed_at' => now(),
            ])->save();

            return $locked->fresh();
        }, attempts: 3);

        // Preserve whether this request created the order so the controller can
        // return 201 for a new purchase even when the provider completed it immediately.
        $updated->wasRecentlyCreated = $order->wasRecentlyCreated;

        return $updated;
    }

    /** @param array<string, mixed> $result */
    private function markAwaitingReconciliation(VtuOrder $order, string $failureCode, array $result = []): VtuOrder
    {
        $updated = DB::transaction(function () use ($order, $failureCode, $result): VtuOrder {
            /** @var VtuOrder $locked */
            $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true)) {
                return $locked;
            }

            $current = VtuOrderStatus::from($locked->status);
            if ($current !== VtuOrderStatus::AwaitingReconciliation
                && $current->canTransitionTo(VtuOrderStatus::AwaitingReconciliation)) {
                $locked->status = VtuOrderStatus::AwaitingReconciliation->value;
            }

            $locked->failure_code = $failureCode;
            $locked->provider_reference = is_string($result['provider_reference'] ?? null)
                ? substr($result['provider_reference'], 0, 150)
                : $locked->provider_reference;
            $locked->safe_provider_response = $this->safeResponse($result);
            $locked->save();

            return $locked->fresh();
        }, attempts: 3);

        $updated->wasRecentlyCreated = $order->wasRecentlyCreated;

        return $updated;
    }

    /** @param array<string, mixed> $result
     *  @return array<string, scalar|null>
     */
    private function safeResponse(array $result): array
    {
        // Never store request headers, tokens, or raw provider payloads.
        return [
            'status' => is_string($result['status'] ?? null) ? $result['status'] : null,
            'provider_reference' => is_string($result['provider_reference'] ?? null)
                ? substr($result['provider_reference'], 0, 150)
                : null,
            'message_code' => is_string($result['message_code'] ?? null)
                ? substr($result['message_code'], 0, 80)
                : null,
        ];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($state, ['23000', '23505'], true)
            && in_array($driverCode, [19, 1062, 1555, 2067], true);
    }
}
