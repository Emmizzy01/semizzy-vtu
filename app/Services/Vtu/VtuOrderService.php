<?php

namespace App\Services\Vtu;

use App\Enums\VtuOrderStatus;
use App\Models\PlatformAddon;
use App\Services\Addons\AddonLifecyclePolicy;
use App\Services\Addons\AddonImplementationRegistry;
use App\Models\User;
use App\Models\BusinessWorkspaceStorefront;
use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
use App\Models\VtuProduct;
use App\Models\PlatformFeatureControl;
use App\Services\IntegrationSettings;
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
use Throwable;

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
        ?string $storefrontSlug = null,
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

        $runtime = app(\App\Services\Addons\ServicePurchaseRuntimeRegistry::class)->forService($product->service_type);
        if ($runtime === null) {
            throw new InvalidArgumentException('This service has no registered purchase runtime.');
        }
        $preparedOrder = $runtime->prepareOrder($product, $serviceDetails, $amountMinor);
        $serviceDetails = $preparedOrder['service_details'];
        $amountMinor = $preparedOrder['amount_minor'];

        if (in_array($product->service_type, ['airtime', 'data'], true)
            && ! preg_match('/^\+?[0-9]{10,15}$/', $recipient)) {
            throw new InvalidArgumentException('Recipient must be a valid phone number.');
        }

        $identityPayload = $runtime instanceof PurchaseRequestIdentityPolicy
            ? $runtime->requestIdentity($product, (int) $user->getKey(), $recipient, $serviceDetails, $amountMinor)
            : [
                'user_id' => $user->getKey(),
                'product_code' => $product->code,
                'recipient' => $recipient,
                'service_details' => $serviceDetails,
                'amount_minor' => $amountMinor,
            ];
        $requestHash = hash('sha256', json_encode(
            $identityPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
        ));

        if ($storefrontSlug !== null) {
            $requestHash = hash('sha256', $requestHash.'|storefront:'.$storefrontSlug);
        }

        $existing = VtuOrder::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $this->returnExisting($existing, $requestHash);
        }

        // A retry of an already accepted order must remain readable when an
        // administrator disables new purchases for this service. The locked
        // transaction path re-checks this gate before creating any new order.
        $this->assertServiceFeatureEnabled($product);

        if (! $product->is_active) {
            throw new InvalidArgumentException('This product is no longer available.');
        }
        $this->assertMappedProviderPurchaseApproved($product);

        if ($storefrontSlug !== null) {
            $this->assertStorefrontProductAvailable($storefrontSlug, $product);
        }

        // Gate the addon before any biller-verification call can reach an external provider.
        // The locked transaction below repeats admission immediately before debit/dispatch
        // to close the race with an administrator disabling the addon during preflight.
        $this->assertMappedAddonAdmission($product);
        $this->provider->assertConfigured();
        $this->provider->assertProductConfigured($product);
        $runtime->verifyBeforePurchase($product, $recipient, $serviceDetails, $this->provider);

        $wallet = Wallet::query()
            ->where('user_id', $user->getKey())
            ->where('currency', 'NGN')
            ->first();

        if ($wallet === null) {
            $wallet = $this->walletAccounts->openForUser($user, 'NGN');
        }

        try {
            $order = DB::transaction(function () use ($user, $wallet, $product, $recipient, $idempotencyKey, $requestHash, $serviceDetails, $amountMinor, $storefrontSlug): VtuOrder {
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
                $this->assertMappedProviderPurchaseApproved($lockedProduct);

                $this->assertServiceFeatureEnabled($lockedProduct);
                $this->provider->assertProductConfigured($lockedProduct);
                $this->assertMappedAddonAdmission($lockedProduct);
                $lockedStorefront = $storefrontSlug !== null
                    ? $this->assertStorefrontProductAvailable($storefrontSlug, $lockedProduct, true)
                    : null;
                $lockedRuntime = app(\App\Services\Addons\ServicePurchaseRuntimeRegistry::class)->forService($lockedProduct->service_type);
                if ($lockedRuntime === null) {
                    throw new InvalidArgumentException('This service has no registered purchase runtime.');
                }
                $lockedPreparedOrder = $lockedRuntime->prepareOrder($lockedProduct, $serviceDetails, $amountMinor);
                $lockedServiceDetails = $lockedPreparedOrder['service_details'];
                $lockedAmountMinor = $lockedPreparedOrder['amount_minor'];
                if (! is_int($lockedAmountMinor) || $lockedAmountMinor < 1) {
                    throw new InvalidArgumentException('The locked product price or purchase amount is invalid.');
                }
                // Delegate addon-specific locked-price policies before debit.
                // Core still owns the product/wallet locks, idempotency and ledger.
                if ($lockedRuntime instanceof LockedPurchasePricePolicy) {
                    $lockedRuntime->assertLockedPriceUnchanged($lockedProduct, (int) $amountMinor, $lockedAmountMinor);
                }
                $lockedWallet = Wallet::query()->whereKey($wallet->getKey())->lockForUpdate()->firstOrFail();
                $policyRejection = $this->levelPolicies->rejectionReason($user, $lockedProduct, (int) $lockedWallet->getKey(), $lockedAmountMinor);
                if ($policyRejection !== null) throw new InvalidArgumentException($policyRejection);

                $order = VtuOrder::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->getKey(),
                    'wallet_id' => $wallet->getKey(),
                    'vtu_product_id' => $lockedProduct->getKey(),
                    'business_workspace_storefront_id' => $lockedStorefront?->getKey(),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'service_type' => $lockedProduct->service_type,
                    'network' => $lockedProduct->network,
                    'product_code' => $lockedProduct->code,
                    'recipient' => $recipient,
                    'amount_minor' => $lockedAmountMinor,
                    'service_details' => $lockedServiceDetails,
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

                // Serialize attempt-number allocation per order. The unique
                // (order, attempt_number) constraint remains the final database
                // guard if a future failover worker races this transaction.
                $lockedAttemptOrder = VtuOrder::query()
                    ->whereKey($order->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $nextAttemptNumber = ((int) VtuProviderAttempt::query()
                    ->where('vtu_order_id', $lockedAttemptOrder->getKey())
                    ->max('attempt_number')) + 1;

                VtuProviderAttempt::query()->create([
                    'vtu_order_id' => $lockedAttemptOrder->getKey(),
                    'attempt_number' => $nextAttemptNumber,
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
            ->orderByDesc('attempt_number')
            ->firstOrFail();

        try {
            $result = $this->provider->submit($order);
        } catch (Throwable $exception) {
            report($exception);
            $attempt->forceFill([
                'outcome' => 'unknown',
                'failure_code' => 'provider_result_unknown',
                'definitive_failure' => false,
                'finished_at' => now(),
            ])->save();
            $updated = $this->markAwaitingReconciliation($order, 'provider_result_unknown', [], $attempt);
            $updated->setAttribute('_created_for_request', true);

            return $updated;
        }

        $providerStatus = $result['status'] ?? 'unknown';
        $definitiveFailure = app(ProviderFailoverPolicy::class)
            ->isDefinitiveFailure((string) $order->provider, $result);
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

        // Reserve an alternate attempt only after the original provider's
        // contract-tested definitive non-delivery signal passes the allowlist.
        // Reservation locks the order and creates attempt #2 before network I/O;
        // it never posts another wallet debit.
        if ($providerStatus === 'failed'
            && app(ProviderFailoverPolicy::class)->mayFailover((string) $attempt->provider, $result)
            && $this->provider instanceof MultiProviderVtuRouter) {
            $secondAttempt = $this->reserveSecondAttempt($order, $attempt, $result);
            if ($secondAttempt !== null) {
                $updated = $this->dispatchReservedAttempt($order->fresh(), $secondAttempt);
                $updated->setAttribute('_created_for_request', true);
                return $updated;
            }
        }

        // Failover is never inferred from a generic failed status or an unknown
        // transport outcome. Those stay on reconciliation without resubmission.
        $updated = $this->applyProviderResult($order, $result, $attempt);
        $updated->setAttribute('_created_for_request', true);

        return $updated;
    }

    /**
     * A storefront is an attribution boundary, not a separate payment engine.
     * Revalidate it under the purchase transaction before any wallet debit.
     */
    private function assertStorefrontProductAvailable(string $slug, VtuProduct $product, bool $lock = false): BusinessWorkspaceStorefront
    {
        $query = BusinessWorkspaceStorefront::query()->where('slug', $slug);
        if ($lock) {
            $query->lockForUpdate();
        }

        $storefront = $query->first();
        if ($storefront === null || $storefront->status !== 'published') {
            throw new InvalidArgumentException('This storefront is not currently available for purchases.');
        }

        $workspaceQuery = $storefront->workspace();
        if ($lock) {
            $workspaceQuery->lockForUpdate();
        }
        $workspace = $workspaceQuery->first();
        if ($workspace === null || $workspace->type !== 'reseller' || $workspace->status !== 'active') {
            throw new InvalidArgumentException('This reseller storefront is not currently available.');
        }

        $enabledServices = $storefront->enabled_services ?? [];
        if (! $product->is_active
            || ! in_array($product->service_type, ['airtime', 'data'], true)
            || ! in_array($product->service_type, $enabledServices, true)
            || $product->currency !== 'NGN') {
            throw new InvalidArgumentException('This product is not enabled for this storefront.');
        }

        if (($product->metadata['catalog_source'] ?? null) === 'vtpass'
            && ($product->metadata['purchase_approved'] ?? false) !== true) {
            throw new InvalidArgumentException('This product is not approved for customer purchases.');
        }

        $integrationSettings = app(\App\Services\IntegrationSettings::class);
        $serviceSetting = $integrationSettings->get('service.'.$product->service_type.'.enabled', null);
        $legacySetting = $integrationSettings->get('service.airtime_data.enabled', true);
        if (! filter_var($serviceSetting ?? $legacySetting, FILTER_VALIDATE_BOOLEAN)) {
            throw new InvalidArgumentException('Airtime and data purchases are temporarily unavailable.');
        }

        return $storefront;
    }

    /** Fail closed until electricity/TV workflows are explicitly implemented and enabled. */
    /**
     * Defence in depth: imported Sogo/GSUBZ rows must retain explicit purchase
     * approval even if a row was activated outside the guarded admin workflow.
     */
    private function assertMappedProviderPurchaseApproved(VtuProduct $product): void
    {
        $metadata = is_array($product->metadata) ? $product->metadata : [];
        $provider = in_array($product->provider_key, ['sogo', 'gsubz', 'dataway'], true)
            ? $product->provider_key
            : ($metadata['catalog_source'] ?? null);

        if (in_array($provider, ['sogo', 'gsubz', 'dataway'], true)
            && ($metadata['purchase_approved'] ?? false) !== true) {
            throw new InvalidArgumentException('This provider product is not approved for customer purchases.');
        }
    }

    /**
     * Enforce Core add-on lifecycle admission for catalogue products explicitly
     * bound to a registered add-on. Legacy/unmapped products retain their
     * existing feature/provider gates; a declared mapping fails closed.
     */
    private function assertMappedAddonAdmission(VtuProduct $product): void
    {
        $metadata = is_array($product->metadata) ? $product->metadata : [];
        // Prefer an explicit reviewed mapping, but never let a legacy catalogue
        // row bypass lifecycle admission merely because its metadata predates addons.
        $addonKey = trim((string) ($metadata['addon_key'] ?? ''));
        if ($addonKey === '') {
            $addonKey = match ($product->service_type) {
                'airtime' => 'service.airtime',
                'data' => 'service.data',
                'electricity-bill' => 'service.electricity',
                'tv-subscription' => 'service.tv',
                default => '',
            };
        }
        if ($addonKey === '') {
            throw new InvalidArgumentException('This service has no registered add-on mapping for new purchases.');
        }

        $implementation = app(AddonImplementationRegistry::class)->find($addonKey);
        // Each independently mapped service uses its own purchase operation.
        // Aggregate legacy addons retain route-operation compatibility only for
        // the service families they historically own during the conversion window.
        $operation = match (true) {
            $addonKey === 'service.airtime' && $product->service_type === 'airtime' => 'service.airtime.purchase',
            $addonKey === 'service.data' && $product->service_type === 'data' => 'service.data.purchase',
            $addonKey === 'service.electricity' && $product->service_type === 'electricity-bill' => 'service.electricity.purchase',
            $addonKey === 'service.tv' && $product->service_type === 'tv-subscription' => 'service.tv.purchase',
            in_array($addonKey, ['service.vtu', 'service.airtime_data'], true)
                && in_array($product->service_type, ['airtime', 'data'], true) => 'wallet.vtu.orders.create',
            in_array($addonKey, ['service.vtu', 'service.bills'], true)
                && in_array($product->service_type, ['electricity-bill', 'tv-subscription'], true) => 'wallet.vtu.orders.create',
            default => null,
        };
        $addon = PlatformAddon::query()->where('addon_key', $addonKey)->first();

        $assessment = app(AddonLifecyclePolicy::class)->assessAdmission(
            (string) ($addon?->status ?? 'missing'),
            (string) ($addon?->compatibility_status ?? 'unchecked'),
            $operation !== null
                && app(\App\Services\Addons\ServicePurchaseAdmissionPolicy::class)
                    ->isRegistered($implementation, $operation),
            true, // assertProductConfigured() has already passed for this locked product.
            true, // assertServiceFeatureEnabled() has already passed.
        );

        if (! $assessment['available']) {
            throw new InvalidArgumentException('This add-on is not available for new purchases ('.$assessment['reason'].').');
        }
    }

    private function assertServiceFeatureEnabled(VtuProduct $product): void
    {
        $integrationSettings = app(IntegrationSettings::class);
        if (in_array($product->service_type, ['airtime', 'data'], true)) {
            // Prefer independent per-service settings. The legacy combined
            // setting remains a fallback for existing installations only.
            $serviceSettingKey = 'service.'.$product->service_type.'.enabled';
            $serviceSetting = $integrationSettings->get($serviceSettingKey, null);
            $legacySetting = $integrationSettings->get('service.airtime_data.enabled', false);
            if (! filter_var($serviceSetting ?? $legacySetting, FILTER_VALIDATE_BOOLEAN)) {
                throw new InvalidArgumentException('This service is not yet enabled for customer purchases.');
            }
        } else {
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

        // Admission gates only new orders. Idempotent existing orders are
        // returned before this method; reconciliation remains Core-owned.
        $addonKey = match ($product->service_type) {
            'airtime' => 'service.airtime',
            'data' => 'service.data',
            'electricity-bill' => 'service.electricity',
            'tv-subscription' => 'service.tv',
            default => null,
        };
        if ($addonKey !== null) {
            $addon = PlatformAddon::query()->where('addon_key', $addonKey)->first();
            $implementation = app(AddonImplementationRegistry::class)->find($addonKey);
            $operation = 'wallet.vtu.orders.create';
            $assessment = $addon === null
                ? ['available' => false, 'reason' => 'addon_manifest_missing']
                : app(AddonLifecyclePolicy::class)->assessAdmission(
                    (string) $addon->status,
                    (string) $addon->compatibility_status,
                    $implementation !== null && in_array($operation, $implementation['operations'], true),
                    true,
                    true,
                );

            if (! ($assessment['available'] ?? false)) {
                throw new InvalidArgumentException('This service add-on is disabled or unavailable for new purchases.');
            }
        }
    }

    /** Re-verify biller details immediately before debiting the wallet. */

    public function reconcile(VtuOrder $order): VtuOrder
    {
        // Recovery is a Core-owned continuation of an already accepted financial
        // operation. It deliberately does not consult new-purchase/add-on admission:
        // disabling a service must not strand a debit whose provider outcome is
        // uncertain. It also never authorizes a new submission by itself.
        $terminal = in_array($order->status, [
            VtuOrderStatus::Successful->value,
            VtuOrderStatus::Failed->value,
            VtuOrderStatus::Cancelled->value,
        ], true);
        $accepted = in_array($order->status, [
            VtuOrderStatus::Processing->value,
            VtuOrderStatus::AwaitingReconciliation->value,
        ], true)
            && $order->dispatch_started_at !== null
            && $order->wallet_transaction_id !== null;
        $coreRecoveryAvailable = $order->dispatch_started_at !== null
            && $order->wallet_transaction_id !== null
            && trim((string) $order->provider) !== '';

        if (! app(AddonLifecyclePolicy::class)->mayContinueAcceptedOperation(
            $accepted,
            $terminal,
            $coreRecoveryAvailable,
        )) {
            return $order;
        }

        $attempt = $this->ensureAttemptForReconciliation($order);

        if ($attempt === null) {
            return $this->markAwaitingReconciliation($order, 'provider_attempt_missing');
        }

        try {
            if ($this->provider instanceof MultiProviderVtuRouter) {
                $result = $this->provider->lookupAttempt($order, $attempt);
            } else {
                if ((string) $attempt->provider !== (string) $order->provider) {
                    throw new LogicException('Recorded provider attempt cannot be reconciled by the configured adapter.');
                }
                $result = $this->provider->lookup($order);
            }
        } catch (Throwable $exception) {
            report($exception);
            return $order->fresh();
        }

        // A lookup must not settle or resubmit an order using a different
        // provider transaction reference. Preserve the recorded reference and
        // leave the debit unresolved for review; a contradictory provider result
        // is not evidence that this order succeeded or definitively failed.
        // Compare against the exact attempt's reference, not the order's
        // current reference, which may already belong to a newer provider.
        $recordedReference = $attempt->provider_reference;
        $lookupReference = $result['provider_reference'] ?? null;
        if (is_string($recordedReference) && $recordedReference !== ''
            && is_string($lookupReference) && $lookupReference !== ''
            && ! hash_equals($recordedReference, $lookupReference)) {
            return $this->markAwaitingReconciliation(
                $order,
                'provider_reference_mismatch',
                ['status' => 'unknown', 'message_code' => 'provider_reference_mismatch'],
                $attempt,
            );
        }

        // A provider may authorize same-key resubmission only with an explicit,
        // contract-tested signal that this transaction was never created.
        if (($result['safe_to_submit_same_key'] ?? false) === true
            && $this->provider instanceof MultiProviderVtuRouter
            && ! in_array($attempt->outcome, ['success', 'failed'], true)) {
            // The provider lookup happens outside a DB transaction. Re-check
            // terminal state, latest-attempt identity, and provider identity
            // under row locks immediately before authorizing network dispatch.
            // The durable outcome claim serializes workers; a crashed worker's
            // claim becomes reclaimable after two minutes.
            $dispatchOrder = $this->claimSafeResubmission($order, $attempt);
            if ($dispatchOrder !== null) {
                $attempt = $attempt->fresh();
                try {
                    $result = $this->provider->submitAttempt($dispatchOrder, $attempt);
                } catch (Throwable $exception) {
                    report($exception);
                    $this->recordReconciledAttemptOutcome($dispatchOrder, $attempt, ['status' => 'unknown']);
                    return $this->markAwaitingReconciliation($dispatchOrder, 'provider_result_unknown', [], $attempt);
                }
            } else {
                return $order->fresh();
            }
        }

        // Persist the lookup/dispatch result against the exact attempt.
        $this->recordReconciledAttemptOutcome($order, $attempt, $result);

        if (! $this->isLatestAttempt($order, $attempt)) {
            return $order->fresh();
        }

        // A definitive, explicitly allowlisted first-attempt failure may reserve
        // one alternate attempt. Reservation and provider identity are committed
        // before the network call; no wallet or ledger operation occurs here.
        if (($result['status'] ?? null) === 'failed'
            && app(ProviderFailoverPolicy::class)->mayFailover((string) $attempt->provider, $result)
            && $this->provider instanceof MultiProviderVtuRouter) {
            $secondAttempt = $this->reserveSecondAttempt($order, $attempt, $result);
            if ($secondAttempt !== null) {
                return $this->dispatchReservedAttempt($order->fresh(), $secondAttempt);
            }
            // A competing reconciler may have reserved attempt #2 while this
            // worker was preparing the same failover. Never apply attempt #1's
            // stale result after that reservation wins the order lock.
            if (! $this->isLatestAttempt($order, $attempt)) {
                return $order->fresh();
            }
        }

        if (($result['status'] ?? null) === 'pending') {
            return $this->markAwaitingReconciliation($order, 'provider_still_pending', $result, $attempt);
        }

        return $this->applyProviderResult($order->fresh(), $result, $attempt);
    }

    /**
     * Atomically authorize one safe same-key recovery dispatch.
     *
     * A recent recovery_dispatching outcome is a durable lease: competing
     * workers must not dispatch while it is active. If a worker dies, a later
     * reconciler may reclaim the lease after two minutes, but only after doing
     * a fresh provider lookup and revalidating the order and exact attempt.
     */
    private function claimSafeResubmission(VtuOrder $order, VtuProviderAttempt $attempt): ?VtuOrder
    {
        return DB::transaction(function () use ($order, $attempt): ?VtuOrder {
            /** @var VtuOrder $lockedOrder */
            $lockedOrder = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($lockedOrder->status, [
                VtuOrderStatus::Processing->value,
                VtuOrderStatus::AwaitingReconciliation->value,
            ], true)
                || (string) $lockedOrder->provider !== (string) $attempt->provider) {
                return null;
            }

            /** @var VtuProviderAttempt|null $lockedAttempt */
            $lockedAttempt = VtuProviderAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null
                || ! $this->isLatestAttemptLocked($lockedOrder, $lockedAttempt)
                || (string) $lockedAttempt->provider !== (string) $lockedOrder->provider
                || in_array($lockedAttempt->outcome, ['success', 'failed'], true)) {
                return null;
            }

            if ($lockedAttempt->outcome === 'recovery_dispatching'
                && $lockedAttempt->updated_at !== null
                && $lockedAttempt->updated_at->greaterThan(now()->subMinutes(2))) {
                return null;
            }

            $lockedAttempt->forceFill(['outcome' => 'recovery_dispatching'])->save();

            return $lockedOrder;
        }, attempts: 3);
    }

    private function isLatestAttemptLocked(VtuOrder $order, VtuProviderAttempt $attempt): bool
    {
        $latest = VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->orderByDesc('attempt_number')
            ->lockForUpdate()
            ->first();

        return $latest !== null && (int) $latest->getKey() === (int) $attempt->getKey();
    }

    private function isLatestAttempt(VtuOrder $order, VtuProviderAttempt $attempt): bool
    {
        return (int) VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->max('attempt_number') === (int) $attempt->attempt_number;
    }

    /**
     * Reserve exactly one alternate provider under the order lock. This method
     * never debits the wallet; the original purchase ledger entry remains the
     * only debit for the order.
     *
     * @param array<string, mixed> $failure
     */
    private function reserveSecondAttempt(VtuOrder $order, VtuProviderAttempt $firstAttempt, array $failure): ?VtuProviderAttempt
    {
        if (! $this->provider instanceof MultiProviderVtuRouter
            || config('services.vtu.multi_provider.enabled', false) !== true
            || (int) $firstAttempt->attempt_number !== 1
            || ! app(ProviderFailoverPolicy::class)->mayFailover((string) $firstAttempt->provider, $failure)) {
            return null;
        }

        try {
            $candidate = $this->provider->nextProviderForAttempt($order->fresh()->load('product'), $firstAttempt);
        } catch (LogicException $exception) {
            // No verified alternate provider is available; preserve the original
            // definitive failure path rather than leaving a half-reserved attempt.
            report($exception);
            return null;
        }

        return DB::transaction(function () use ($order, $firstAttempt, $failure, $candidate): ?VtuProviderAttempt {
            /** @var VtuOrder $lockedOrder */
            $lockedOrder = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($lockedOrder->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true)) {
                return null;
            }

            $latest = VtuProviderAttempt::query()
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($latest === null
                || (int) $latest->getKey() !== (int) $firstAttempt->getKey()
                || (int) $latest->attempt_number !== 1
                || $latest->outcome !== 'failed'
                || ! $latest->definitive_failure
                || (string) $lockedOrder->provider !== (string) $latest->provider) {
                return null;
            }

            $lockedOrder->forceFill([
                'provider' => $candidate,
                'status' => VtuOrderStatus::Processing->value,
                'failure_code' => null,
            ])->save();

            return VtuProviderAttempt::query()->create([
                'vtu_order_id' => $lockedOrder->getKey(),
                'attempt_number' => 2,
                'provider' => $candidate,
                'outcome' => 'reserved',
                'request_fingerprint' => hash('sha256', implode('|', [
                    $lockedOrder->public_id,
                    $candidate,
                    $lockedOrder->product_code,
                    $lockedOrder->recipient,
                    (string) $lockedOrder->amount_minor,
                ])),
                'started_at' => now(),
            ]);
        }, attempts: 3);
    }

    private function dispatchReservedAttempt(VtuOrder $order, VtuProviderAttempt $attempt): VtuOrder
    {
        if (! $this->provider instanceof MultiProviderVtuRouter) {
            return $this->markAwaitingReconciliation($order, 'provider_attempt_recovery_unavailable', [], $attempt);
        }

        // Atomically consume the reserved dispatch right before network I/O.
        // A duplicate worker can no longer submit the same reserved attempt
        // while the first worker is still in flight.
        $claimedAttempt = DB::transaction(function () use ($order, $attempt): ?VtuProviderAttempt {
            $lockedOrder = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($lockedOrder->status, [
                VtuOrderStatus::Processing->value,
                VtuOrderStatus::AwaitingReconciliation->value,
            ], true) || (string) $lockedOrder->provider !== (string) $attempt->provider) {
                return null;
            }

            $lockedAttempt = VtuProviderAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->lockForUpdate()
                ->first();
            $latestAttemptNumber = (int) VtuProviderAttempt::query()
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->max('attempt_number');

            if ($lockedAttempt === null
                || $lockedAttempt->outcome !== 'reserved'
                || (int) $lockedAttempt->attempt_number !== $latestAttemptNumber
                || (string) $lockedAttempt->provider !== (string) $lockedOrder->provider) {
                return null;
            }

            $lockedAttempt->forceFill(['outcome' => 'recovery_dispatching'])->save();

            return $lockedAttempt;
        }, attempts: 3);

        if ($claimedAttempt === null) {
            return $order->fresh();
        }

        $attempt = $claimedAttempt;
        $order = $order->fresh();

        try {
            $result = $this->provider->submitAttempt($order, $attempt);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordReconciledAttemptOutcome($order, $attempt, ['status' => 'unknown']);
            return $this->markAwaitingReconciliation($order, 'provider_result_unknown', [], $attempt);
        }

        $this->recordReconciledAttemptOutcome($order, $attempt, $result);
        if (! $this->isLatestAttempt($order, $attempt)) {
            return $order->fresh();
        }
        if (($result['status'] ?? null) === 'pending') {
            return $this->markAwaitingReconciliation($order, 'provider_still_pending', $result, $attempt);
        }

        return $this->applyProviderResult($order->fresh(), $result, $attempt);
    }

    /**
     * Backfill a durable lookup record for legacy dispatched orders created before
     * provider-attempt history was available. This records history only; it never
     * authorizes a new provider submission or a second wallet debit.
     */
    private function ensureAttemptForReconciliation(VtuOrder $order): ?VtuProviderAttempt
    {
        return DB::transaction(function () use ($order): ?VtuProviderAttempt {
            /** @var VtuOrder $lockedOrder */
            $lockedOrder = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $attempt = VtuProviderAttempt::query()
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->orderByDesc('attempt_number')
                ->lockForUpdate()
                ->first();

            if ($attempt !== null) {
                return $attempt;
            }

            if ($lockedOrder->dispatch_started_at === null || ! is_string($lockedOrder->provider) || $lockedOrder->provider === '') {
                return null;
            }

            return VtuProviderAttempt::query()->create([
                'vtu_order_id' => $lockedOrder->getKey(),
                'attempt_number' => 1,
                'provider' => $lockedOrder->provider,
                'outcome' => 'unknown',
                'request_fingerprint' => hash('sha256', implode('|', [
                    $lockedOrder->public_id,
                    $lockedOrder->provider,
                    $lockedOrder->product_code,
                    $lockedOrder->recipient,
                    (string) $lockedOrder->amount_minor,
                ])),
                'started_at' => $lockedOrder->dispatch_started_at,
            ]);
        }, attempts: 3);
    }

    /**
     * Update the durable history for the original submission after a lookup.
     * This does not create a new purchase attempt: lookups are recovery work,
     * not permission to resubmit to this or another provider.
     *
     * @param array<string, mixed> $result
     */
    private function recordReconciledAttemptOutcome(VtuOrder $order, VtuProviderAttempt $attempt, array $result): void
    {
        DB::transaction(function () use ($order, $attempt, $result): void {
            /** @var VtuOrder $lockedOrder */
            $lockedOrder = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($lockedOrder->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true)) {
                return;
            }

            // Update only the exact attempt that was queried. A newer attempt
            // may have been reserved while this provider lookup was in flight.
            $lockedAttempt = VtuProviderAttempt::query()
                ->whereKey($attempt->getKey())
                ->where('vtu_order_id', $lockedOrder->getKey())
                ->lockForUpdate()
                ->first();

            if ($lockedAttempt === null || in_array($lockedAttempt->outcome, ['success', 'failed'], true)) {
                return;
            }

            $status = $result['status'] ?? 'unknown';
            $knownStatus = is_string($status) && in_array($status, ['success', 'failed', 'pending'], true);
            $definitiveFailure = $knownStatus
                && app(ProviderFailoverPolicy::class)->isDefinitiveFailure((string) $lockedAttempt->provider, $result);

            $lockedAttempt->forceFill([
                'outcome' => $knownStatus ? $status : 'unknown',
                'provider_reference' => is_string($result['provider_reference'] ?? null)
                    ? substr($result['provider_reference'], 0, 191)
                    : $lockedAttempt->provider_reference,
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
    private function applyProviderResult(VtuOrder $order, array $result, ?VtuProviderAttempt $sourceAttempt = null): VtuOrder
    {
        $providerStatus = $result['status'] ?? null;

        // Defense in depth: never interpret an unrecognized status as a
        // definitive failure, because that would incorrectly refund a possibly
        // fulfilled order. Only explicit allowlisted states may be applied.
        if (! is_string($providerStatus)
            || ! in_array($providerStatus, ['success', 'failed', 'pending'], true)) {
            return $this->markAwaitingReconciliation($order, 'provider_result_unknown', $result, $sourceAttempt);
        }

        // A plain "failed" label is not enough to refund: only a provider
        // whose definitive non-delivery contract is explicitly allowlisted,
        // and whose response carries the strict boolean signal, is terminal.
        // This also applies to reconciliation results and verified webhooks.
        if ($providerStatus === 'failed'
            && ! app(ProviderFailoverPolicy::class)->isDefinitiveFailure((string) $order->provider, $result)) {
            return $this->markAwaitingReconciliation($order, 'provider_failure_not_verified', $result, $sourceAttempt);
        }

        if ($providerStatus === 'pending') {
            // Reconciliation may race with a terminal result. Lock and re-read
            // the row so a late "pending" response can never undo success/failure.
            $updated = DB::transaction(function () use ($order, $result, $sourceAttempt): VtuOrder {
                /** @var VtuOrder $locked */
                $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, [
                    VtuOrderStatus::Successful->value,
                    VtuOrderStatus::Failed->value,
                    VtuOrderStatus::Cancelled->value,
                ], true) || ($sourceAttempt !== null && ! $this->isLatestAttemptLocked($locked, $sourceAttempt))) {
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

        $updated = DB::transaction(function () use ($order, $providerStatus, $result, $sourceAttempt): VtuOrder {
            /** @var VtuOrder $locked */
            $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true) || ($sourceAttempt !== null && ! $this->isLatestAttemptLocked($locked, $sourceAttempt))) {
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

            if ($next === VtuOrderStatus::Successful) {
                app(\App\Services\Workspaces\StorefrontCommissionService::class)->accrueSuccessfulOrder($locked);
            }

            return $locked->fresh();
        }, attempts: 3);

        // Preserve whether this request created the order so the controller can
        // return 201 for a new purchase even when the provider completed it immediately.
        $updated->wasRecentlyCreated = $order->wasRecentlyCreated;

        return $updated;
    }

    /** @param array<string, mixed> $result */
    private function markAwaitingReconciliation(VtuOrder $order, string $failureCode, array $result = [], ?VtuProviderAttempt $sourceAttempt = null): VtuOrder
    {
        $updated = DB::transaction(function () use ($order, $failureCode, $result, $sourceAttempt): VtuOrder {
            /** @var VtuOrder $locked */
            $locked = VtuOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [
                VtuOrderStatus::Successful->value,
                VtuOrderStatus::Failed->value,
                VtuOrderStatus::Cancelled->value,
            ], true) || ($sourceAttempt !== null && ! $this->isLatestAttemptLocked($locked, $sourceAttempt))) {
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
