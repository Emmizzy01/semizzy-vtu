<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceStorefront;
use App\Models\BusinessWorkspaceCommission;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Models\PlatformFeatureControl;
use App\Services\Vtu\VtuOrderService;
use App\Services\IntegrationSettings;
use App\Services\Vtu\VtuProvider;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests exercise the electricity/TV workflow internals under an
        // explicitly enabled test configuration. Production defaults remain
        // disabled until provider-specific workflows are approved.
        PlatformFeatureControl::query()->whereIn('feature_key', ['service.airtime_data', 'service.electricity', 'service.tv'])
            ->update(['is_enabled' => true, 'is_implemented' => true]);
        app(IntegrationSettings::class)->set('service.airtime_data.enabled', '1', null);

        config([
            'services.vtu.name' => 'test_provider',
            'services.vtu.driver' => 'test_provider',
            // Test-only provider contract explicitly identifies definitive non-delivery.
            'services.vtu.multi_provider.definitive_failure_providers' => ['test_provider'],
            'services.vtu.endpoint' => 'https://provider.example.test/api',
            'services.vtu.token' => 'test-secret-token',
            // These explicit VTpass adapter contract tests run only with a test-only opt-in.
            'services.vtu.vtpass.purchase_isolated' => false,
        ]);
    }

    public function test_inactive_catalogue_product_cannot_be_purchased_or_debited(): void
    {
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['is_active' => false])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'inactive-catalogue-product-1',
            );
            $this->fail('Inactive catalogue products must not be purchasable.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_missing_airtime_data_feature_control_blocks_direct_service_purchase(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        PlatformFeatureControl::query()->where('feature_key', 'service.airtime_data')->delete();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'missing-airtime-data-control-direct-service',
                [],
                10000,
            );
            $this->fail('The VTU service must reject airtime/data purchases when the feature control is missing.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('service is not yet enabled', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
        }
    }

    public function test_disabled_airtime_data_setting_blocks_direct_service_purchase(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        app(IntegrationSettings::class)->set('service.airtime_data.enabled', '0', $user->id);
        Http::fake();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'disabled-airtime-data-setting-direct-service',
                [],
                10000,
            );
            $this->fail('The VTU service must reject airtime/data purchases when the platform setting is disabled.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('service is not yet enabled', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_purchase_rechecks_catalogue_price_under_lock_before_debiting(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        // This regression covers catalogue-priced data plans. Airtime now uses
        // the customer's explicit amount, so its charge must not follow a
        // later catalogue face-value change.
        $product->forceFill(['service_type' => 'data'])->save();

        $provider = new class((int) $product->getKey()) implements VtuProvider {
            public int $submissions = 0;

            public function __construct(private readonly int $productId)
            {
            }

            public function assertConfigured(): void
            {
                // Simulate a catalogue edit after the service's initial read
                // but before its order transaction begins.
                VtuProduct::query()->whereKey($this->productId)->update(['price_minor' => 2000]);
            }

            public function assertProductConfigured(VtuProduct $product): void
            {
            }

            public function submit(VtuOrder $order): array
            {
                $this->submissions++;

                return ['status' => 'pending'];
            }

            public function lookup(VtuOrder $order): array
            {
                return ['status' => 'pending'];
            }
        };
        $this->app->instance(VtuProvider::class, $provider);

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'catalogue-price-race-1',
            );
            $this->fail('Purchase must use the latest locked catalogue price.');
        } catch (InsufficientWalletFunds) {
            $this->assertSame(2000, (int) $product->fresh()->price_minor);
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            $this->assertSame(0, $provider->submissions);
        }
    }

    public function test_purchase_rechecks_provider_approval_under_lock_before_debiting(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'service_type' => 'data',
            'provider_key' => 'sogo',
            'metadata' => [
                'catalog_source' => 'sogo',
                'provider_mappings' => ['sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-1gb-7d']],
                'purchase_approved' => true,
            ],
        ])->save();

        $provider = new class((int) $product->getKey()) implements VtuProvider {
            public int $submissions = 0;

            public function __construct(private readonly int $productId)
            {
            }

            public function assertConfigured(): void
            {
                // Simulate approval being revoked after preflight but before
                // the purchase transaction locks and re-reads the catalogue row.
                $current = VtuProduct::query()->whereKey($this->productId)->firstOrFail();
                $metadata = $current->metadata ?? [];
                $metadata['purchase_approved'] = false;
                $current->forceFill(['metadata' => $metadata])->save();
            }

            public function assertProductConfigured(VtuProduct $product): void
            {
            }

            public function submit(VtuOrder $order): array
            {
                $this->submissions++;

                return ['status' => 'pending'];
            }

            public function lookup(VtuOrder $order): array
            {
                return ['status' => 'pending'];
            }
        };
        $this->app->instance(VtuProvider::class, $provider);

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'provider-approval-race-1',
            );
            $this->fail('Purchase must reject provider approval revoked after preflight.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('not approved for customer purchases', $exception->getMessage());
            $this->assertFalse((bool) $product->fresh()->metadata['purchase_approved']);
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            $this->assertSame(0, $provider->submissions);
        }
    }

    public function test_active_sogo_catalogue_product_without_purchase_approval_is_blocked_before_debit(): void
    {
        Http::fake();
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'provider_key' => 'sogo',
            'metadata' => [
                'catalog_source' => 'sogo',
                'provider_mappings' => ['sogo' => ['network' => 'mtn']],
                'purchase_approved' => false,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'sogo-active-without-approval',
            );
            $this->fail('An active Sogo catalogue row without explicit purchase approval must be blocked.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('not approved for customer purchases', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_active_gsubz_catalogue_product_without_purchase_approval_is_blocked_before_debit(): void
    {
        Http::fake();
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'provider_key' => 'gsubz',
            'metadata' => [
                'catalog_source' => 'gsubz',
                'provider_mappings' => ['gsubz' => ['network' => 'mtn-data', 'plan_code' => 'gsubz-1gb-7d']],
                'purchase_approved' => false,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'gsubz-active-without-approval',
            );
            $this->fail('An active GSUBZ catalogue row without explicit purchase approval must be blocked.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('not approved for customer purchases', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_vtpass_legacy_product_without_catalog_source_or_approval_is_blocked_before_debit(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'network' => 'mtn',
            'face_value_minor' => 1000,
            'metadata' => ['provider_service_id' => 'mtn'],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'vtpass-legacy-untagged-no-approval',
            );
            $this->fail('An untagged legacy VTpass product without explicit approval must be blocked.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('sandbox verification', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_vtpass_product_without_recorded_sandbox_approval_cannot_submit_even_if_legacy_row_is_active(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'network' => 'mtn',
            'face_value_minor' => 1000,
            'metadata' => ['purchase_approved' => true,
                'catalog_source' => 'vtpass',
                'provider_service_id' => 'mtn',
                'purchase_approved' => false,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'vtpass-awaiting-approval-1',
            );
            $this->fail('A VTpass product without recorded sandbox approval must not be submitted.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('sandbox verification', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_vtpass_data_product_without_verified_variation_is_rejected_before_debit(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'service_type' => 'data',
            'network' => 'mtn',
            'metadata' => ['purchase_approved' => true,],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'vtpass-missing-variation-1',
            );
            $this->fail('Unmapped data products must be rejected before a customer debit.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('variation code', $exception->getMessage());
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_isolated_vtpass_purchase_is_blocked_before_order_creation_or_wallet_debit(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
            'services.vtu.vtpass.purchase_isolated' => true,
        ]);
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill([
            'network' => 'mtn',
            'face_value_minor' => 1000,
            'metadata' => ['purchase_approved' => true, 'provider_service_id' => 'mtn'],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'vtpass-isolated-no-debit',
            );
            $this->fail('An isolated provider must be blocked before order creation or wallet debit.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('temporarily isolated', $exception->getMessage());
        }

        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
    }

    public function test_vtpass_adapter_can_process_a_sandbox_shaped_airtime_success(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => [
                'status' => 'delivered',
                'transactionId' => 'vtpass-sandbox-order-1',
            ]],
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['network' => 'mtn', 'face_value_minor' => 1000, 'metadata' => ['purchase_approved' => true]])->save();

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'vtpass-sandbox-workflow-1',
        );

        $this->assertSame('successful', $order->status);
        $this->assertSame('vtpass', $order->provider);
        $this->assertSame('vtpass-sandbox-order-1', $order->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/pay'
            && $request->hasHeader('api-key', 'sandbox-api-key')
            && $request->hasHeader('secret-key', 'sandbox-secret-key')
            && $request['serviceID'] === 'mtn'
            && $request['amount'] === 10);
    }

    public function test_custom_airtime_amount_is_charged_and_sent_to_vtpass_instead_of_catalogue_price(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'vtpass-custom-airtime']],
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 5000);
        $product->forceFill([
            'network' => 'mtn',
            'face_value_minor' => 1000,
            'metadata' => ['purchase_approved' => true,'provider_service_id' => 'mtn'],
        ])->save();

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'vtpass-custom-airtime-amount',
            [],
            2500,
        );

        $this->assertSame('successful', $order->status);
        $this->assertSame(2500, (int) $order->amount_minor);
        $this->assertSame(2500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/pay'
            && $request['serviceID'] === 'mtn'
            && $request['amount'] === 25);
    }

    public function test_successful_purchase_is_persisted_and_debited_once(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'provider_reference' => 'provider-order-001',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);

        $order = app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'purchase-success-1');

        $this->assertSame('successful', $order->status);
        $this->assertSame('provider-order-001', $order->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(1);
    }

    public function test_duplicate_successful_purchase_is_not_submitted_or_debited_twice(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'provider-order-duplicate-success',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $first = $service->purchase($user, $product->code, '08012345678', 'duplicate-success-key');
        $duplicate = $service->purchase($user, $product->code, '08012345678', 'duplicate-success-key');

        $this->assertSame('successful', $first->status);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $order = VtuOrder::query()->sole();
        $debit = \App\Models\WalletTransaction::query()
            ->where('reference', $order->public_id)
            ->where('type', 'debit')
            ->sole();
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('wallet_ledger_entries')
            ->where('wallet_transaction_id', $debit->getKey())
            ->count(), 'The purchase debit must have exactly one ledger entry.');
        $this->assertSame('posted', $debit->status);
        $this->assertSame(1, \App\Models\WalletTransaction::query()
            ->where('idempotency_key', 'vtu-purchase:'.$order->public_id)
            ->count(), 'The order debit idempotency key must be unique.');
        $this->assertSame(1, $attempts, 'A duplicate successful purchase must not be resubmitted.');
    }

    public function test_concurrent_duplicate_request_during_provider_submission_does_not_repeat_financial_or_provider_effects(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $nestedDuplicate = null;
        $submissionCount = 0;

        Http::fake(function () use (&$nestedDuplicate, &$submissionCount, $service, $user, $product) {
            $submissionCount++;
            // Simulate a duplicate request arriving while the first provider call is in flight.
            $nestedDuplicate = $service->purchase(
                $user,
                $product->code,
                '08012345678',
                'concurrent-duplicate-in-flight',
            );

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'provider-order-concurrent-duplicate',
            ], 200);
        });

        $first = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'concurrent-duplicate-in-flight',
        );

        $this->assertNotNull($nestedDuplicate);
        $this->assertSame($first->id, $nestedDuplicate->id);
        $this->assertSame('successful', $first->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $order = VtuOrder::query()->sole();
        $debit = \App\Models\WalletTransaction::query()
            ->where('reference', $order->public_id)
            ->where('type', 'debit')
            ->sole();
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('wallet_ledger_entries')
            ->where('wallet_transaction_id', $debit->getKey())
            ->count(), 'The in-flight duplicate must not create another ledger entry.');
        $this->assertSame(1, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->count(), 'The in-flight duplicate must not create another provider attempt.');
        $this->assertSame(1, $submissionCount, 'The in-flight duplicate must not submit to the provider twice.');
        Http::assertSentCount(1);
    }

    public function test_definitive_provider_failure_refunds_through_the_ledger(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'failed',
            'definitive_failure' => true,
            'message_code' => 'INVALID_RECIPIENT',
            'provider_reference' => 'provider-order-002',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);

        $order = app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'purchase-failed-1');

        $this->assertSame('failed', $order->status);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 3);
        Http::assertSentCount(1);
    }

    public function test_unexpected_provider_exception_quarantines_order_and_duplicate_does_not_resubmit(): void
    {
        $submissions = 0;
        Http::fake(function ($request) use (&$submissions) {
            if ($request->method() === 'POST') {
                $submissions++;
                throw new \RuntimeException('Unexpected adapter failure after dispatch began.');
            }

            return Http::response(['status' => 'unknown'], 503);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $first = $service->purchase($user, $product->code, '08012345678', 'runtime-provider-failure-001');
        $duplicate = $service->purchase($user, $product->code, '08012345678', 'runtime-provider-failure-001');

        $this->assertSame('awaiting_reconciliation', $first->status);
        $this->assertSame($first->getKey(), $duplicate->getKey());
        $this->assertSame(1, $submissions, 'An unclassified provider exception must not cause duplicate submission.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $first->getKey(),
            'outcome' => 'unknown',
            'failure_code' => 'provider_result_unknown',
        ]);
    }

    public function test_sogo_not_found_recovery_resubmits_same_attempt_identity_without_second_debit(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz'],
            'services.vtu.multi_provider.definitive_failure_providers' => [],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);
        $sogoPosts = 0;
        Http::fake(function ($request) use (&$sogoPosts) {
            if (str_contains($request->url(), 'api.sogo.africa') && $request->method() === 'GET') {
                return Http::response(['message' => 'not found'], 404);
            }
            if (str_contains($request->url(), 'api.sogo.africa') && $request->method() === 'POST') {
                $sogoPosts++;
                if ($sogoPosts === 1) {
                    return Http::response(['message' => 'temporary upstream error'], 503);
                }

                return Http::response([
                    'data' => ['status' => 'completed', 'reference' => 'SOGO-RECOVERED'],
                ], 200);
            }

            return Http::response(['status' => 'unknown'], 503);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'sogo-safe-recovery-1');
        $this->assertSame('awaiting_reconciliation', $order->status);

        $recovered = $service->reconcile($order->fresh());

        $this->assertSame('successful', $recovered->status);
        $this->assertSame(1, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count());
        $this->assertSame('success', \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->value('outcome'));
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'Recovery must not create another wallet debit.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(2, $sogoPosts, 'Only the initial submission and contract-authorized same-key recovery may submit.');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $order->public_id));
    }

    public function test_ambiguous_second_attempt_stays_in_reconciliation_without_third_attempt_or_refund(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz', 'dataway'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo', 'gsubz'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);
        Http::fake([
            'api.sogo.africa/*' => Http::response([
                'data' => ['status' => 'failed', 'reference' => 'SOGO-DECLINED'],
            ], 200),
            'api.gsubz.com/*' => Http::response(['message' => 'gateway timeout'], 503),
        ]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'sogo-ambiguous-alternate-1',
        );

        $this->assertSame('awaiting_reconciliation', $order->status);
        $this->assertSame('gsubz', $order->provider);
        $this->assertSame(2, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count());
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 2,
            'provider' => 'gsubz',
            'outcome' => 'unknown',
        ]);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'An ambiguous second attempt must not refund or debit again.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'POST');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com')
            && $request->method() === 'POST');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dataway'));

        // Repeated recovery must only query the uncertain second attempt. It
        // must not create attempt #3, submit another purchase, or refund.
        $reconciled = app(VtuOrderService::class)->reconcile($order->fresh());
        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame(2, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count());
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count());
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $postRequests = Http::recorded(fn ($request) => $request->method() === 'POST'
            && ! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/verify/'));
        $this->assertCount(2, $postRequests, 'Recovery must not submit a third purchase.');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dataway'));
    }

    public function test_definitive_sogo_failure_reserves_second_attempt_before_alternate_submission_without_second_debit(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);
        Http::fake([
            'api.sogo.africa/*' => Http::response([
                'data' => ['status' => 'failed', 'reference' => 'SOGO-DECLINED'],
            ], 200),
            'api.gsubz.com/*' => Http::response([
                'status' => 'success', 'reference' => 'GSUBZ-OK',
            ], 200),
        ]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'sogo-definitive-failover-1',
        );

        $this->assertSame('successful', $order->status);
        $this->assertSame('gsubz', $order->provider);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 1,
            'provider' => 'sogo',
            'outcome' => 'failed',
            'definitive_failure' => 1,
        ]);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 2,
            'provider' => 'gsubz',
            'outcome' => 'success',
        ]);
        $this->assertSame(2, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count());
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'Only the initial funding credit and one purchase debit may exist.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'POST');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com')
            && $request->method() === 'POST'
            && ($request['requestID'] ?? null) === $order->public_id);
    }

    public function test_safe_recovery_does_not_resubmit_if_order_becomes_terminal_after_lookup(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
        ]);

        $submissionCount = 0;
        $orderId = null;
        Http::fake(function ($request) use (&$submissionCount, &$orderId) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($path, '/transactions/')) {
                // Simulate a verified webhook winning after lookup but before
                // the recovery worker's final locked dispatch authorization.
                if ($orderId !== null) {
                    \Illuminate\Support\Facades\DB::table('vtu_orders')
                        ->where('id', $orderId)
                        ->update(['status' => 'successful', 'updated_at' => now()]);
                }
                return Http::response([], 404);
            }
            if (str_contains($path, '/bills/airtime')) {
                $submissionCount++;
                if ($submissionCount === 1) {
                    throw new ConnectionException('Simulated original dispatch timeout.');
                }
                return Http::response(['data' => ['status' => 'completed']], 200);
            }
            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => ['sogo' => ['network' => 'mtn']]]])->save();
        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'recovery-terminal-race');
        $orderId = $order->getKey();
        $attempt = \App\Models\VtuProviderAttempt::query()->where('vtu_order_id', $orderId)->firstOrFail();
        unset($order->_created_for_request);
        $order->forceFill(['provider' => 'sogo', 'status' => 'processing'])->save();
        $attempt->forceFill(['provider' => 'sogo', 'outcome' => 'reserved'])->save();

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('successful', $reconciled->status);
        $this->assertSame(1, $submissionCount, 'A terminal order must not trigger a recovery submission.');
        $this->assertSame('reserved', $attempt->fresh()->outcome);
    }

    public function test_durable_recovery_claim_blocks_competing_worker_during_same_key_resubmission(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
        ]);

        $submissionCount = 0;
        $orderId = null;
        $nestedReconcileTriggered = false;
        Http::fake(function ($request) use (&$submissionCount, &$orderId, &$nestedReconcileTriggered) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_contains($path, '/transactions/')) {
                return Http::response([], 404);
            }
            if (str_contains($path, '/bills/airtime')) {
                $submissionCount++;
                if ($submissionCount === 1) {
                    throw new ConnectionException('Simulated original dispatch timeout.');
                }
                if (! $nestedReconcileTriggered) {
                    $nestedReconcileTriggered = true;
                    // A competing worker enters while the first worker owns the
                    // durable claim. It must lookup, then decline dispatch.
                    app(VtuOrderService::class)->reconcile(VtuOrder::query()->findOrFail($orderId));
                }
                return Http::response(['data' => ['status' => 'completed', 'reference' => 'RECOVERED-ONCE']], 200);
            }
            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => ['sogo' => ['network' => 'mtn']]]])->save();
        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'recovery-competing-workers');
        $orderId = $order->getKey();
        $attempt = \App\Models\VtuProviderAttempt::query()->where('vtu_order_id', $orderId)->firstOrFail();
        unset($order->_created_for_request);
        $order->forceFill(['provider' => 'sogo', 'status' => 'processing'])->save();
        $attempt->forceFill(['provider' => 'sogo', 'outcome' => 'reserved'])->save();

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('successful', $reconciled->status);
        $this->assertSame(2, $submissionCount, 'Only the original dispatch and one recovery dispatch are allowed.');
        $this->assertSame('success', $attempt->fresh()->outcome);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    public function test_stale_recovery_dispatch_lease_is_reclaimed_after_fresh_not_found_lookup(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
        ]);

        $submissionCount = 0;
        Http::fake(function ($request) use (&$submissionCount) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/transactions/')) {
                return Http::response([], 404);
            }

            if (str_contains($path, '/bills/airtime')) {
                $submissionCount++;
                if ($submissionCount === 1) {
                    throw new ConnectionException('Simulated original dispatch timeout.');
                }

                return Http::response([
                    'data' => ['status' => 'completed', 'reference' => 'STALE-LEASE-RECOVERED'],
                ], 200);
            }

            return Http::response(['status' => 'unknown'], 503);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
        ]]])->save();

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'stale-recovery-lease-1');
        $this->assertSame('awaiting_reconciliation', $order->status);

        $attempt = \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->firstOrFail();
        \Illuminate\Support\Facades\DB::table('vtu_provider_attempts')
            ->where('id', $attempt->getKey())
            ->update([
                'outcome' => 'recovery_dispatching',
                'updated_at' => now()->subMinutes(3),
            ]);

        $recovered = $service->reconcile($order->fresh());

        $this->assertSame('successful', $recovered->status);
        $this->assertSame(2, $submissionCount, 'Only the original dispatch and one lease-reclaimed resubmission are allowed.');
        $this->assertSame(1, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count());
        $this->assertSame('success', $attempt->fresh()->outcome);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'Lease recovery must not debit the wallet twice.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'GET'
            && str_contains($request->url(), '/transactions/'.$order->public_id));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $order->public_id));
    }

    public function test_reserved_sogo_attempt_recovers_after_crash_only_on_explicit_not_found_signal(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
        ]);
        $submissionCount = 0;
        Http::fake(function ($request) use (&$submissionCount) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($path, '/transactions/')) {
                // The provider confirms that no transaction exists for this identity.
                return Http::response([], 404);
            }

            if (str_contains($path, '/bills/airtime')) {
                $submissionCount++;

                // Simulate a connection drop before the provider accepts or creates
                // the transaction. Recovery must first obtain the explicit 404 above.
                if ($submissionCount === 1) {
                    throw new ConnectionException('Connection dropped before provider acceptance.');
                }

                return Http::response([
                    'data' => ['status' => 'completed', 'reference' => 'SOGO-RECOVERED'],
                ], 200);
            }

            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
        ]]])->save();

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'reserved-sogo-crash-recovery');
        $attempt = \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->firstOrFail();

        // The first dispatch hit a connection failure before provider acceptance.
        // Persist the crash checkpoint as a reserved attempt, as if the worker died
        // after reservation and before it could record a provider outcome.
        unset($order->_created_for_request);
        unset($order->_created_for_request);
        $order->forceFill(['provider' => 'sogo', 'status' => 'processing'])->save();
        $attempt->forceFill(['provider' => 'sogo', 'outcome' => 'reserved', 'definitive_failure' => false])->save();

        $recovered = $service->reconcile($order->fresh());

        $recoveryEvidence = [
            'order_status' => $recovered->status,
            'order_failure_code' => $recovered->failure_code,
            'order_provider' => $recovered->provider,
            'attempt_provider' => $attempt->fresh()->provider,
            'attempt_outcome' => $attempt->fresh()->outcome,
            'attempt_failure_code' => $attempt->fresh()->failure_code,
            'attempt_definitive_failure' => $attempt->fresh()->definitive_failure,
            'requests' => Http::recorded()->map(fn ($pair) => [
                'method' => $pair[0]->method(),
                'url' => $pair[0]->url(),
                'idempotency_key' => $pair[0]->header('Idempotency-Key'),
                'response_status' => $pair[1]?->status(),
                'response_body' => $pair[1]?->json(),
            ])->all(),
        ];

        $this->assertSame('successful', $recovered->status, json_encode($recoveryEvidence));
        $this->assertSame('sogo', $recovered->provider);
        $this->assertSame('success', $attempt->fresh()->outcome);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('vtu_provider_attempts', 1);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transactions/')
            && $request->method() === 'GET');
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bills/airtime')
            && $request->method() === 'POST'
            && $request->hasHeader('Idempotency-Key', $order->public_id));
        $this->assertSame(2, $submissionCount, 'Only the original failed dispatch and the one safe recovery resubmission are allowed.');
    }

    public function test_generic_failed_provider_status_stays_unresolved_without_refund(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'failed',
            'message_code' => 'UNVERIFIED_FAILURE',
            'provider_reference' => 'provider-order-unverified-failure',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'purchase-unverified-failure-1',
        );

        $this->assertSame('awaiting_reconciliation', $order->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 1,
            'provider' => $order->provider,
            'outcome' => 'failed',
            'definitive_failure' => false,
        ]);
        $this->assertSame(
            1,
            \App\Models\VtuProviderAttempt::query()->where('vtu_order_id', $order->getKey())->count(),
            'An unverified failure must not create a second provider attempt.',
        );
        Http::assertSentCount(1);
    }

    public function test_vtpass_invalid_request_id_does_not_trigger_automatic_resubmission_or_refund(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $sequence = Http::sequence()
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'pending']]], 200)
            ->push(['code' => '015', 'response_description' => 'INVALID REQUEST ID'], 200)
            ->push(['code' => '015', 'response_description' => 'INVALID REQUEST ID'], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $sequence]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['network' => 'mtn', 'face_value_minor' => 1000, 'metadata' => ['purchase_approved' => true]])->save();
        $service = app(VtuOrderService::class);

        $order = $service->purchase($user, $product->code, '08012345678', 'vtpass-not-found-no-retry');
        $this->assertSame('processing', $order->status);

        $firstLookup = $service->reconcile($order->fresh());
        $this->assertSame('awaiting_reconciliation', $firstLookup->status);
        $secondLookup = $service->reconcile($firstLookup->fresh());

        $this->assertSame('awaiting_reconciliation', $secondLookup->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/pay');
        Http::assertSentCount(3);
        $payRequests = Http::recorded(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/pay');
        $requeryRequests = Http::recorded(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/requery');
        $this->assertCount(1, $payRequests, 'A not-found lookup must not automatically resubmit the purchase.');
        $this->assertCount(2, $requeryRequests);
        $this->assertSame($payRequests->first()[0]['request_id'], $requeryRequests->first()[0]['request_id']);
        $this->assertSame($requeryRequests->first()[0]['request_id'], $requeryRequests->last()[0]['request_id']);
    }

    public function test_timeout_marks_order_for_reconciliation_and_retry_does_not_resubmit(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Simulated provider timeout');
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $first = $service->purchase($user, $product->code, '08012345678', 'purchase-timeout-1');
        $retry = $service->purchase($user, $product->code, '08012345678', 'purchase-timeout-1');

        $this->assertSame('awaiting_reconciliation', $first->status);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, $attempts, 'A duplicate request must not resubmit the timed-out purchase.');
    }

    public function test_provider_authentication_errors_remain_ambiguous_without_refund(): void
    {
        Http::fake(fn () => Http::response(['error' => 'unauthorized'], 401));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $order = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'provider-auth-error-1',
        );

        $this->assertSame('awaiting_reconciliation', $order->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(2);
        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-secret-token')
                && str_starts_with($request->url(), 'https://provider.example.test/');
        });
    }

    public function test_pending_provider_order_can_be_reconciled_without_resubmitting(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending', 'provider_reference' => 'provider-order-003'], 200);
            }

            return Http::response(['status' => 'success', 'provider_reference' => 'provider-order-003'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $order = $service->purchase($user, $product->code, '08012345678', 'purchase-reconcile-1');
        $this->assertSame('processing', $order->status);

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('successful', $reconciled->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 1,
            'provider' => $order->provider,
            'outcome' => 'success',
            'provider_reference' => 'provider-order-003',
        ]);
        Http::assertSentCount(2);
    }

    public function test_provider_attempt_router_rejects_cross_order_and_wrong_provider_attempts(): void
    {
        Http::fake(['*' => Http::response(['status' => 'pending'], 200)]);

        [$firstUser, , $firstProduct] = $this->preparedCustomer(1000, 1500);
        [$secondUser, , $secondProduct] = $this->preparedCustomer(1000, 1500);

        $service = app(VtuOrderService::class);
        $firstOrder = $service->purchase(
            $firstUser,
            $firstProduct->code,
            '08012345678',
            'attempt-owner-first-order',
        );
        $secondOrder = $service->purchase(
            $secondUser,
            $secondProduct->code,
            '08012345678',
            'attempt-owner-second-order',
        );

        $firstAttempt = \\App\\Models\\VtuProviderAttempt::query()
            ->where('vtu_order_id', $firstOrder->getKey())
            ->firstOrFail();
        $router = app(\\App\\Services\\Vtu\\MultiProviderVtuRouter::class);

        try {
            $router->lookupAttempt($secondOrder, $firstAttempt);
            $this->fail('An attempt belonging to another order must not be looked up.');
        } catch (\\LogicException $exception) {
            $this->assertStringContainsString('does not belong', $exception->getMessage());
        }

        $firstAttempt->forceFill(['provider' => 'sogo'])->save();

        try {
            $router->submitAttempt($firstOrder, $firstAttempt);
            $this->fail('An attempt whose provider differs from the order dispatch target must not be submitted.');
        } catch (\\LogicException $exception) {
            $this->assertStringContainsString('does not match the order dispatch target', $exception->getMessage());
        }

        $this->assertSame(500, (int) $firstOrder->wallet->fresh()->available_balance_minor);
        $this->assertSame(500, (int) $secondOrder->wallet->fresh()->available_balance_minor);
        Http::assertSentCount(2);
    }

    public function test_late_success_from_old_provider_attempt_cannot_settle_newer_attempt(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);

        $orderId = null;
        $advanced = false;
        Http::fake(function ($request) use (&$orderId, &$advanced) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($request->url(), 'api.sogo.africa') && str_contains($path, '/bills/airtime')) {
                return Http::response(['data' => ['status' => 'pending', 'reference' => 'SOGO-OLD-REF']], 200);
            }

            if (str_contains($request->url(), 'api.sogo.africa') && str_contains($path, '/transactions/')) {
                if (! $advanced && $orderId !== null) {
                    $advanced = true;
                    $order = \App\Models\VtuOrder::query()->findOrFail($orderId);
                    \Illuminate\Support\Facades\DB::table('vtu_orders')->where('id', $orderId)->update([
                        'provider' => 'gsubz',
                        'provider_reference' => 'GSUBZ-NEW-REF',
                        'status' => 'processing',
                        'updated_at' => now(),
                    ]);
                    \App\Models\VtuProviderAttempt::query()->create([
                        'vtu_order_id' => $orderId,
                        'attempt_number' => 2,
                        'provider' => 'gsubz',
                        'provider_reference' => 'GSUBZ-NEW-REF',
                        'outcome' => 'reserved',
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $order->public_id,
                            'gsubz',
                            $order->product_code,
                            $order->recipient,
                            (string) $order->amount_minor,
                        ])),
                        'started_at' => now(),
                    ]);
                }

                return Http::response(['data' => ['status' => 'completed', 'reference' => 'SOGO-OLD-REF']], 200);
            }

            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'late-old-provider-success');
        $orderId = $order->getKey();

        $reconciled = $service->reconcile($order->fresh());

        $this->assertTrue($advanced, 'The test must reserve the newer attempt while the older lookup is in flight.');
        $this->assertSame('processing', $reconciled->status);
        $this->assertSame('gsubz', $reconciled->provider);
        $this->assertSame('GSUBZ-NEW-REF', $reconciled->provider_reference);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $orderId,
            'attempt_number' => 1,
            'provider' => 'sogo',
            'outcome' => 'success',
        ]);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $orderId,
            'attempt_number' => 2,
            'provider' => 'gsubz',
            'outcome' => 'reserved',
        ]);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count());
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com'));
    }

    public function test_late_definitive_failure_from_old_provider_attempt_cannot_refund_newer_attempt(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);

        $orderId = null;
        $advanced = false;
        Http::fake(function ($request) use (&$orderId, &$advanced) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($request->url(), 'api.sogo.africa') && str_contains($path, '/bills/airtime')) {
                return Http::response(['data' => ['status' => 'pending', 'reference' => 'SOGO-OLD-REF']], 200);
            }

            if (str_contains($request->url(), 'api.sogo.africa') && str_contains($path, '/transactions/')) {
                if (! $advanced && $orderId !== null) {
                    $advanced = true;
                    $order = \App\Models\VtuOrder::query()->findOrFail($orderId);
                    \Illuminate\Support\Facades\DB::table('vtu_orders')->where('id', $orderId)->update([
                        'provider' => 'gsubz',
                        'provider_reference' => 'GSUBZ-NEW-REF',
                        'status' => 'processing',
                        'updated_at' => now(),
                    ]);
                    \App\Models\VtuProviderAttempt::query()->create([
                        'vtu_order_id' => $orderId,
                        'attempt_number' => 2,
                        'provider' => 'gsubz',
                        'provider_reference' => 'GSUBZ-NEW-REF',
                        'outcome' => 'reserved',
                        'request_fingerprint' => hash('sha256', implode('|', [
                            $order->public_id,
                            'gsubz',
                            $order->product_code,
                            $order->recipient,
                            (string) $order->amount_minor,
                        ])),
                        'started_at' => now(),
                    ]);
                }

                return Http::response(['data' => ['status' => 'failed', 'reference' => 'SOGO-OLD-REF', 'definitive_failure' => true, 'message_code' => 'declined']], 200);
            }

            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'late-old-provider-success');
        $orderId = $order->getKey();

        $reconciled = $service->reconcile($order->fresh());

        $this->assertTrue($advanced, 'The test must reserve the newer attempt while the older lookup is in flight.');
        $this->assertSame('processing', $reconciled->status);
        $this->assertSame('gsubz', $reconciled->provider);
        $this->assertSame('GSUBZ-NEW-REF', $reconciled->provider_reference);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $orderId,
            'attempt_number' => 1,
            'provider' => 'sogo',
            'outcome' => 'failed',
        ]);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $orderId,
            'attempt_number' => 2,
            'provider' => 'gsubz',
            'outcome' => 'reserved',
        ]);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count());
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com'));
    }

    public function test_late_webhook_from_old_provider_attempt_cannot_touch_newer_attempt(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);
        Http::fake([
            'api.sogo.africa/*' => Http::response([
                'data' => ['status' => 'pending', 'reference' => 'SOGO-WEBHOOK-OLD-REF'],
            ], 200),
            'api.gsubz.com/*' => Http::response(['status' => 'pending'], 200),
        ]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'late-old-provider-webhook',
        );
        $this->assertSame('sogo', $order->provider);

        $oldReference = (string) $order->provider_reference;
        \Illuminate\Support\Facades\DB::table('vtu_orders')
            ->where('id', $order->getKey())
            ->update([
                'provider' => 'gsubz',
                'provider_reference' => 'GSUBZ-WEBHOOK-NEW-REF',
                'status' => 'processing',
            ]);

        $event = \App\Models\VtuProviderWebhookEvent::query()->create([
            'provider' => 'sogo',
            'event_id' => 'evt-old-provider-attempt',
            'event_type' => 'transaction.completed',
            'provider_reference' => $oldReference,
            'payload' => [
                'id' => 'evt-old-provider-attempt',
                'event' => 'transaction.completed',
                'data' => ['reference' => $oldReference, 'status' => 'completed'],
            ],
            'payload_sha256' => hash('sha256', 'evt-old-provider-attempt'),
            'received_at' => now(),
        ]);

        $stats = app(\App\Services\Vtu\VtuProviderWebhookService::class)->process();
        $fresh = $order->fresh();

        $this->assertSame(1, $stats['unmatched']);
        $this->assertNull($event->fresh()->processed_at, 'A stale webhook must remain retryable/unmatched, not be applied to the newer attempt.');
        $this->assertSame('processing', $fresh->status);
        $this->assertSame('gsubz', $fresh->provider);
        $this->assertSame('GSUBZ-WEBHOOK-NEW-REF', $fresh->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count());
    }

    public function test_signed_sogo_failed_and_refunded_webhooks_cannot_refund_without_definitive_failure_signal(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.sogo.webhook_secret' => 'sogo-webhook-test-secret',
        ]);
        Http::fake([
            'api.sogo.africa/*' => Http::response([
                'data' => ['status' => 'pending', 'reference' => 'SOGO-REFUND-SAFETY-REF'],
            ], 200),
        ]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
        ]]])->save();

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'signed-webhook-refund-safety',
        );
        $this->assertSame('sogo', $order->provider);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);

        foreach (['transaction.failed', 'transaction.refunded'] as $index => $eventType) {
            $eventId = 'evt-sogo-refund-safety-'.$index;
            $payload = [
                'id' => $eventId,
                'event' => $eventType,
                'data' => ['reference' => $order->provider_reference, 'status' => 'failed'],
            ];
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
            $signature = 'sha256='.hash_hmac('sha256', $body, 'sogo-webhook-test-secret');

            $this->call('POST', '/api/vtu/webhooks/sogo', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SOGO_SIGNATURE_256' => $signature,
            ], $body)->assertStatus(202);

            app(\App\Services\Vtu\VtuProviderWebhookService::class)->process();
        }

        $fresh = $order->fresh();
        $this->assertSame('awaiting_reconciliation', $fresh->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count());
        $this->assertDatabaseMissing('wallet_transactions', [
            'reference' => 'vtu-refund:'.$order->id,
        ]);
        $this->assertSame(2, \App\Models\VtuProviderWebhookEvent::query()
            ->where('provider', 'sogo')->whereNotNull('processed_at')->count());
    }

    public function test_reconciliation_with_a_different_provider_reference_stays_unresolved(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'status' => 'pending',
                    'provider_reference' => 'expected-provider-reference',
                ], 200);
            }

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'different-provider-transaction',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $order = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'mismatched-provider-reference-reconciliation',
        );

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame('provider_reference_mismatch', $reconciled->failure_code);
        $this->assertSame('expected-provider-reference', $reconciled->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, \App\Models\VtuProviderAttempt::query()->where('vtu_order_id', $order->getKey())->count());
        Http::assertSentCount(2);
    }

    public function test_unrecognized_provider_status_stays_unresolved_without_refund(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            if ($request->method() === 'POST') {
                return Http::response([
                    'status' => 'completed-ish',
                    'provider_reference' => 'provider-order-unknown-status',
                ], 200);
            }

            return Http::response([
                'status' => 'completed-ish',
                'provider_reference' => 'provider-order-unknown-status',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'unknown-provider-status-1');

        $this->assertSame('awaiting_reconciliation', $order->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'Unrecognized provider status must never trigger a refund.');
        $this->assertSame(2, $requests);
    }

    public function test_reconciliation_failure_refunds_once_and_terminal_order_is_not_queried_again(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;

            if ($request->method() === 'POST') {
                return Http::response([
                    'status' => 'pending',
                    'provider_reference' => 'provider-order-refund-once',
                ], 200);
            }

            return Http::response([
                'status' => 'failed',
                'definitive_failure' => true,
                'message_code' => 'PROVIDER_DECLINED',
                'provider_reference' => 'provider-order-refund-once',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'reconcile-refund-once-1');

        $failed = $service->reconcile($order->fresh());
        $again = $service->reconcile($failed->fresh());

        $this->assertSame('failed', $failed->status);
        $this->assertSame('failed', $again->status);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertSame(2, $requests, 'A terminal order must not be queried or refunded again.');
    }

    public function test_dataway_pending_webhook_cannot_regress_successful_order_or_change_wallet_balance(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response([
                    'status' => 'pending',
                    'provider_reference' => 'provider-order-webhook-race',
                ], 200);
            }

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'provider-order-webhook-race',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $order = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'dataway-pending-webhook-after-success',
        );

        $successful = $service->reconcile($order);
        $this->assertSame('successful', $successful->status);
        $balanceAfterSuccess = (int) $wallet->fresh()->available_balance_minor;
        $transactionsAfterSuccess = \App\Models\WalletTransaction::query()->count();

        // Model a verified Dataway event matching the persisted provider identity.
        $successful->forceFill([
            'provider' => 'dataway',
            'provider_reference' => 'dataway-webhook-race-reference',
        ])->save();

        $payload = [
            'event' => 'transaction.pending',
            'data' => [
                'reference' => 'dataway-webhook-race-reference',
                'status' => 'pending',
            ],
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        \App\Models\VtuProviderWebhookEvent::query()->create([
            'provider' => 'dataway',
            'event_id' => hash('sha256', $body),
            'event_type' => 'transaction.pending',
            'provider_reference' => 'dataway-webhook-race-reference',
            'payload' => $payload,
            'payload_sha256' => hash('sha256', $body),
            'received_at' => now(),
        ]);

        $stats = app(\App\Services\Vtu\VtuProviderWebhookService::class)->process();

        $this->assertSame(1, $stats['processed']);
        $this->assertSame('successful', $successful->fresh()->status);
        $this->assertSame($balanceAfterSuccess, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame($transactionsAfterSuccess, \App\Models\WalletTransaction::query()->count());
        $this->assertDatabaseMissing('vtu_provider_webhook_events', [
            'provider' => 'dataway',
            'event_type' => 'transaction.pending',
            'processed_at' => null,
        ]);
        $this->assertDatabaseHas('vtu_provider_webhook_events', [
            'provider' => 'dataway',
            'event_type' => 'transaction.pending',
            'processing_error' => null,
        ]);
    }

    public function test_late_pending_reconciliation_cannot_regress_a_terminal_order(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending'], 200);
            }

            $lookups++;

            return Http::response([
                'status' => $lookups === 1 ? 'success' : 'pending',
                'provider_reference' => 'provider-order-race-safe',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $staleOrder = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'late-pending-must-not-regress',
        );

        $successful = $service->reconcile($staleOrder);
        $latePending = $service->reconcile($staleOrder);

        $this->assertSame('successful', $successful->status);
        $this->assertSame('successful', $latePending->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(2, $lookups, 'The stale order should trigger two lookups but no extra submission.');
        Http::assertSentCount(3);
    }

    public function test_late_failure_reconciliation_cannot_refund_an_already_successful_order(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending'], 200);
            }

            $lookups++;

            return Http::response([
                'status' => $lookups === 1 ? 'success' : 'failed',
                'message_code' => 'LATE_FAILURE',
                'provider_reference' => 'provider-order-late-failure',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $staleOrder = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'late-failure-must-not-refund',
        );

        $successful = $service->reconcile($staleOrder);
        $lateFailure = $service->reconcile($staleOrder);

        $this->assertSame('successful', $successful->status);
        $this->assertSame('successful', $lateFailure->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(2, $lookups, 'The stale order may be looked up twice but cannot trigger a late refund.');
        Http::assertSentCount(3);
    }

    public function test_two_stale_reconciliations_cannot_refund_the_same_definitive_failure_twice(): void
    {
        $lookups = 0;
        Http::fake(function ($request) use (&$lookups) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending'], 200);
            }

            $lookups++;

            return Http::response([
                'status' => 'failed',
                'definitive_failure' => true,
                'message_code' => 'PROVIDER_DECLINED',
                'provider_reference' => 'provider-order-concurrent-failure',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);
        $staleOrder = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'concurrent-failure-refund-once',
        );

        // Both reconciliations intentionally use the same stale non-terminal
        // snapshot, modelling workers that looked up the provider concurrently.
        $firstFailure = $service->reconcile($staleOrder);
        $secondFailure = $service->reconcile($staleOrder);

        $this->assertSame('failed', $firstFailure->status);
        $this->assertSame('failed', $secondFailure->status);
        $this->assertSame('failed', $staleOrder->fresh()->status);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertSame(2, $lookups);
        Http::assertSentCount(3);
    }

    public function test_reusing_idempotency_key_for_different_purchase_is_rejected(): void
    {
        Http::fake(fn () => Http::response(['status' => 'pending'], 200));

        [$user, , $product] = $this->preparedCustomer(1000, 5000);
        $service = app(VtuOrderService::class);

        $service->purchase($user, $product->code, '08012345678', 'same-purchase-key');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $service->purchase($user, $product->code, '08087654321', 'same-purchase-key');
    }

    public function test_airtime_uses_explicit_customer_amount_and_ignores_client_supplied_minor_units(): void
    {
        Http::fake(fn () => Http::response(['status' => 'success'], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 3500);
        $user->forceFill(['transaction_pin_hash' => bcrypt('4928')])->save();

        $response = $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'idempotency_key' => 'client-price-tamper-1',
            'transaction_pin' => '4928',
            'amount' => 25,
            'amount_minor' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount_minor', 2500);

        $this->assertSame(1000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
    }

    public function test_customer_cannot_view_another_customers_vtu_order(): void
    {
        Http::fake(fn () => Http::response(['status' => 'pending'], 200));

        [$owner, , $product] = $this->preparedCustomer(1000, 1500);
        $order = app(VtuOrderService::class)->purchase(
            $owner,
            $product->code,
            '08012345678',
            'private-order-check-1',
        );

        $otherCustomer = User::query()->create([
            'name' => 'Different Customer',
            'email' => 'different-customer-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);

        $this->actingAs($otherCustomer)
            ->getJson('/wallet/vtu/orders/'.$order->public_id)
            ->assertNotFound();
    }

    /** @return array{User, \App\Models\Wallet, VtuProduct} */
    public function test_recovery_with_unavailable_provider_outcome_never_resubmits_or_refunds(): void
    {
        $submissions = 0;
        $lookups = 0;

        Http::fake(function ($request) use (&$submissions, &$lookups) {
            if ($request->method() === 'POST') {
                $submissions++;
                // The provider may have accepted this request before the connection failed.
                throw new ConnectionException('Simulated lost provider response');
            }

            $lookups++;

            // The provider cannot yet confirm whether the purchase completed.
            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $first = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'unavailable-provider-outcome-1',
        );
        $retry = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'unavailable-provider-outcome-1',
        );

        $this->assertSame('awaiting_reconciliation', $first->status);
        $this->assertSame($first->id, $retry->id);

        $firstLookup = $service->reconcile($first->fresh());
        $secondLookup = $service->reconcile($firstLookup->fresh());

        $this->assertSame('awaiting_reconciliation', $firstLookup->status);
        $this->assertSame('awaiting_reconciliation', $secondLookup->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, $submissions, 'An uncertain order must never be submitted a second time.');
        $this->assertSame(2, $lookups, 'Recovery should query the existing order rather than submit another purchase.');
        // Laravel's HTTP fake records the two completed GET lookups; the POST
        // throws a ConnectionException before it is added to the recorded list.
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/orders/'.$first->public_id));
    }

    public function test_reconciliation_keeps_ambiguous_order_flag_when_provider_still_reports_pending(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;
            if ($request->method() === 'POST') {
                throw new ConnectionException('Simulated submission timeout');
            }

            return Http::response([
                'status' => 'pending',
                'provider_reference' => 'provider-order-still-pending',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $order = $service->purchase($user, $product->code, '08012345678', 'pending-after-timeout-1');
        $this->assertSame('awaiting_reconciliation', $order->status);

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame('provider-order-still-pending', $reconciled->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(2, $requests, 'The submission attempt and reconciliation lookup should both occur.');
    }

    public function test_insufficient_funds_does_not_submit_or_create_vtu_order(): void
    {
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1500, 1000);

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'insufficient-funds-1',
            );
            $this->fail('The purchase should be rejected when the wallet balance is insufficient.');
        } catch (\App\Exceptions\Wallet\InsufficientWalletFunds) {
            $this->assertSame(1000, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    public function test_reconciliation_command_only_checks_orders_past_the_backoff_window(): void
    {
        config([
            'services.vtu.driver' => 'configured',
            'services.vtu.endpoint' => 'https://provider.example.test/api',
            'services.vtu.token' => 'test-secret-token',
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending'], 200);
            }

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'provider-order-recovered',
            ], 200);
        });

        [$oldUser, , $oldProduct] = $this->preparedCustomer(1000, 1500);
        [$recentUser, , $recentProduct] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $oldOrder = $service->purchase(
            $oldUser,
            $oldProduct->code,
            '08012345678',
            'reconcile-backoff-old',
        );
        $recentOrder = $service->purchase(
            $recentUser,
            $recentProduct->code,
            '08012345678',
            'reconcile-backoff-recent',
        );

        $oldTimestamp = now()->subMinutes(6);
        $recentTimestamp = now()->subMinutes(2);

        // Use query-builder updates so Eloquent does not replace updated_at with now().
        VtuOrder::query()->whereKey($oldOrder->getKey())->update([
            'dispatch_started_at' => $oldTimestamp,
            'updated_at' => $oldTimestamp,
        ]);
        VtuOrder::query()->whereKey($recentOrder->getKey())->update([
            'dispatch_started_at' => $recentTimestamp,
            'updated_at' => $recentTimestamp,
        ]);

        $this->artisan('vtu:reconcile-orders --limit=100')->assertExitCode(0);

        $this->assertSame('successful', $oldOrder->fresh()->status);
        $this->assertSame('processing', $recentOrder->fresh()->status);
        Http::assertSentCount(3);
    }

    /** @return array{User, \App\Models\Wallet, VtuProduct} */
    public function test_reconciliation_recovers_an_order_left_processing_after_a_crash(): void
    {
        config([
            'services.vtu.driver' => 'configured',
            'services.vtu.endpoint' => 'https://provider.example.test/api',
            'services.vtu.token' => 'test-secret-token',
        ]);

        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'status' => 'success',
                    'provider_reference' => 'provider-order-after-crash',
                ], 200);
            }

            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $order = VtuOrder::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'crash-recovery-order-1',
            'request_hash' => hash('sha256', 'simulated-crash-recovery'),
            'service_type' => $product->service_type,
            'network' => $product->network,
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => $product->price_minor,
            'currency' => 'NGN',
            'status' => 'processing',
            'provider' => 'test_provider',
            'dispatch_started_at' => now()->subMinutes(10),
        ]);

        $debit = app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'debit',
            amountMinor: (int) $product->price_minor,
            idempotencyKey: 'vtu-purchase:'.$order->public_id,
            reference: $order->public_id,
            description: 'VTU purchase: '.$product->name,
            actorUserId: (int) $user->getKey(),
            metadata: ['vtu_order_id' => $order->public_id],
            correlationId: $order->public_id,
        );

        $order->forceFill([
            'wallet_transaction_id' => $debit->getKey(),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        // Simulate the scheduled worker finding a persisted order after the
        // original application process stopped before saving a provider result.
        $this->artisan('vtu:reconcile-orders --limit=100')->assertExitCode(0);

        $this->assertSame('successful', $order->fresh()->status);
        $this->assertSame('provider-order-after-crash', $order->fresh()->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/orders/'.$order->public_id));
    }


    public function test_authorised_staff_can_reconcile_order_without_resubmitting_purchase(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending'], 200);
            }

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'provider-manual-reconcile-1',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $order = app(VtuOrderService::class)->purchase(
            $user, $product->code, '08012345678', 'manual-reconcile-order-1'
        );
        $staff = User::query()->create([
            'name' => 'VTU Operations Staff',
            'email' => 'vtu-ops-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $this->actingAs($staff)
            ->post('/admin/vtu-orders/'.$order->public_id.'/reconcile')
            ->assertRedirect();

        $this->assertSame('successful', $order->fresh()->status);
        $this->assertSame('provider-manual-reconcile-1', $order->fresh()->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(2, $requests, 'One original submission plus one lookup; reconciliation must not resubmit.');
        Http::assertSentCount(2);
    }

    public function test_customer_cannot_trigger_admin_vtu_reconciliation(): void
    {
        $customer = User::query()->create([
            'name' => 'VTU Customer',
            'email' => 'vtu-customer-denied-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);

        $this->actingAs($customer)
            ->post('/admin/vtu-orders/'.(string) \Illuminate\Support\Str::uuid().'/reconcile')
            ->assertForbidden();
    }

    public function test_education_and_insurance_catalogue_entries_cannot_debit_or_submit_before_service_flows_are_implemented(): void
    {
        Http::fake();

        foreach (['education', 'insurance'] as $serviceType) {
            [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
            $product->forceFill([
                'service_type' => $serviceType,
                'network' => $serviceType,
                'metadata' => ['purchase_approved' => true,
                    'provider_service_id' => $serviceType === 'education' ? 'waec' : 'third-party',
                    'purchase_supported' => false,
                    'setup_required' => ['customer_verification', 'recipient_fields', 'purchase_payload'],
                ],
            ])->save();

            try {
                app(VtuOrderService::class)->purchase(
                    $user,
                    $product->code,
                    '1234567890',
                    'unsupported-'.$serviceType.'-'.uniqid(),
                );
                $this->fail(ucfirst($serviceType).' purchases must remain disabled until their provider flow is verified.');
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
                $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            }

            $this->assertDatabaseMissing('vtu_orders', ['product_code' => $product->code]);
        }

        Http::assertNothingSent();
    }

    public function test_electricity_purchase_verifies_before_debit_and_duplicate_key_submits_once(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $sequence = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'Meter Customer']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => [
                'status' => 'delivered',
                'transactionId' => 'electricity-workflow-order',
            ]]], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $sequence]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 150000);
        $product->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        $service = app(VtuOrderService::class);
        $details = ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'];
        $first = $service->purchase($user, $product->code, '12345678901', 'electricity-idempotency-1', $details, 50000);
        $duplicate = $service->purchase($user, $product->code, '12345678901', 'electricity-idempotency-1', $details, 50000);

        $this->assertSame('successful', $first->status);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(100000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(2);
        $requests = Http::recorded();
        $this->assertSame('/api/merchant-verify', parse_url($requests[0][0]->url(), PHP_URL_PATH));
        $this->assertSame('/api/pay', parse_url($requests[1][0]->url(), PHP_URL_PATH));
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
    }

    public function test_verified_tv_subscription_duplicate_request_is_charged_and_submitted_once(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $sequence = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'TV Subscriber']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => [
                'status' => 'delivered',
                'transactionId' => 'tv-workflow-order',
            ]]], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $sequence]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 300000);
        $product->forceFill([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'price_minor' => 250000,
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ])->save();

        $service = app(VtuOrderService::class);
        $details = ['contact_phone' => '08012345678'];
        $first = $service->purchase($user, $product->code, '1234567890', 'tv-idempotency-1', $details);
        $duplicate = $service->purchase($user, $product->code, '1234567890', 'tv-idempotency-1', $details);

        $this->assertSame('successful', $first->status);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(50000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(2);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/merchant-verify'))->count());
    }

    public function test_electricity_idempotency_key_reuse_with_changed_amount_is_rejected_without_second_provider_call(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $sequence = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'Meter Customer']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => [
                'status' => 'delivered',
                'transactionId' => 'electricity-idempotency-conflict',
            ]]], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $sequence]);

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 250000);
        $product->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        $service = app(VtuOrderService::class);
        $details = ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'];
        $first = $service->purchase($user, $product->code, '12345678901', 'electricity-idempotency-conflict-1', $details, 50000);

        try {
            $service->purchase($user, $product->code, '12345678901', 'electricity-idempotency-conflict-1', $details, 60000);
            $this->fail('Reusing an idempotency key for a different amount must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException) {
            $this->assertSame('successful', $first->status);
        }

        $this->assertSame(200000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(2);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
    }

    public function test_tv_smartcard_verification_failure_cannot_create_order_or_debit_wallet(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake(fn () => Http::response([
            'code' => '099',
            'response_description' => 'Smartcard not found',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 300000);
        $product->forceFill([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'price_minor' => 250000,
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '1234567890',
                'tv-unverified-smartcard-1',
                ['contact_phone' => '08012345678'],
            );
            $this->fail('An unverified smartcard must never reach purchase dispatch.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('could not confirm', $exception->getMessage());
        }

        $this->assertSame(300000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/merchant-verify'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
    }

    public function test_electricity_meter_verification_failure_cannot_debit_wallet_or_create_order(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => [],
            'response_description' => 'Meter verification did not return customer details',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 200000);
        $product->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '12345678901',
                'electricity-unverified-meter-1',
                ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
                50000,
            );
            $this->fail('An unverified meter must never be debited or submitted for payment.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('could not confirm', $exception->getMessage());
        }

        $this->assertSame(200000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/merchant-verify'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
    }

    public function test_authenticated_customer_can_recover_existing_order_by_idempotency_key(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 5000);
        $provider = new class implements VtuProvider {
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function submit(VtuOrder $order): array { return ['status' => 'pending']; }
            public function lookup(VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);

        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'recover-existing-order-key',
        );
        \Illuminate\Support\Facades\DB::table('vtu_orders')->where('id', $order->getKey())->update(['provider_reference' => 'internal-provider-reference-must-not-leak']);

        $recovery = $this->actingAs($user)->getJson('/wallet/vtu/orders/recover?idempotency_key=recover-existing-order-key')
            ->assertOk()
            ->assertJsonPath('data.id', $order->public_id)
            ->assertJsonPath('data.status', 'processing');

        $publicProductCode = (string) $recovery->json('data.product_code');
        $this->assertMatchesRegularExpression('/^p[0-9a-z]+\\.[a-f0-9]{32}$/', $publicProductCode);
        $this->assertNotSame($product->code, $publicProductCode);
        $this->assertArrayNotHasKey('provider', $recovery->json('data'));
        $this->assertArrayNotHasKey('provider_key', $recovery->json('data'));
        $this->assertArrayNotHasKey('provider_product_code', $recovery->json('data'));
        $this->assertArrayNotHasKey('provider_reference', $recovery->json('data'));
        $this->assertStringNotContainsString('internal-provider-reference-must-not-leak', $recovery->getContent());

        $this->actingAs($user)->getJson('/wallet/vtu/orders/'.$order->public_id)
            ->assertOk()
            ->assertJsonPath('data.product_code', $publicProductCode)
            ->assertJsonMissingPath('data.provider')
            ->assertJsonMissingPath('data.provider_key')
            ->assertJsonMissingPath('data.provider_reference')
            ->assertDontSee('internal-provider-reference-must-not-leak');

        $this->actingAs($user)->getJson('/wallet/vtu/orders/recover?idempotency_key=not-created-yet')
            ->assertNotFound()
            ->assertJsonPath('data', null);

        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertSame(4000, (int) $wallet->fresh()->available_balance_minor);
    }

    public function test_electricity_limits_are_rechecked_after_catalogue_changes_during_verification(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 200000);
        $product->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        $provider = new class((int) $product->getKey()) extends \App\Services\Vtu\VtpassProviderClient {
            public int $submissions = 0;
            public function __construct(private readonly int $productId) {}
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function verifyElectricityMeter(VtuProduct $product, string $meterNumber, string $meterType): array
            {
                $fresh = VtuProduct::query()->findOrFail($this->productId);
                $metadata = $fresh->metadata;
                $metadata['provider_minimum_amount'] = 200;
                $fresh->forceFill(['metadata' => $metadata])->save();
                return ['status' => 'verified', 'message_code' => '000', 'customer_name' => 'Test Customer', 'customer_address' => null];
            }
            public function submit(VtuOrder $order): array
            {
                $this->submissions++;
                return ['status' => 'success'];
            }
            public function lookup(VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '12345678901',
                'electricity-catalogue-limits-race',
                ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
                10000,
            );
            $this->fail('The locked catalogue minimum must be enforced after verification.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('outside the provider limits', $exception->getMessage());
        }

        $this->assertSame(200000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertSame(0, $provider->submissions);
    }

    public function test_electricity_provider_minimum_and_maximum_amount_boundaries_are_accepted(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $sequence = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'Minimum Customer']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'electricity-minimum']]], 200)
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'Maximum Customer']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'electricity-maximum']]], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $sequence]);

        foreach ([['minimum', 10000], ['maximum', 10000000]] as [$label, $amountMinor]) {
            [$user, $wallet, $product] = $this->preparedCustomer(1000, 20000000);
            $product->forceFill([
                'service_type' => 'electricity-bill',
                'network' => 'ikeja-electric',
                'metadata' => ['purchase_approved' => true,
                    'provider_service_id' => 'ikeja-electric',
                    'provider_minimum_amount' => 100,
                    'provider_maximum_amount' => 100000,
                    'purchase_supported' => true,
                ],
            ])->save();

            $order = app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '12345678901',
                'electricity-boundary-'.$label,
                ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
                $amountMinor,
            );

            $this->assertSame('successful', $order->status);
            $this->assertSame($amountMinor, (int) $order->amount_minor);
            $this->assertSame(20000000 - $amountMinor, (int) $wallet->fresh()->available_balance_minor);
        }

        $this->assertDatabaseCount('vtu_orders', 2);
        $this->assertSame(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
        $this->assertSame(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/merchant-verify'))->count());
    }

    public function test_electricity_amount_outside_provider_limits_is_rejected_before_verification_or_debit(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 200000);
        $product->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '12345678901',
                'electricity-out-of-range-1',
                ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
                5000,
            );
            $this->fail('An amount below the configured provider minimum must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('outside the provider limits', $exception->getMessage());
        }

        $this->assertSame(200000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertNothingSent();
    }

    public function test_electricity_and_tv_duplicate_purchases_verify_and_debit_only_once(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $responses = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'Meter Holder']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'meter-order-1']]], 200)
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'TV Holder']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'tv-order-1']]], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $responses]);

        [$electricUser, $electricWallet, $electricProduct] = $this->preparedCustomer(50000, 200000);
        $electricProduct->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        $service = app(VtuOrderService::class);
        $electricFirst = $service->purchase(
            $electricUser,
            $electricProduct->code,
            '12345678901',
            'electricity-duplicate-key',
            ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
            50000,
        );
        $electricDuplicate = $service->purchase(
            $electricUser,
            $electricProduct->code,
            '12345678901',
            'electricity-duplicate-key',
            ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
            50000,
        );

        [$tvUser, $tvWallet, $tvProduct] = $this->preparedCustomer(50000, 200000);
        $tvProduct->forceFill([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ])->save();

        $tvFirst = $service->purchase(
            $tvUser,
            $tvProduct->code,
            '1234567890',
            'tv-duplicate-key',
            ['contact_phone' => '08012345678'],
        );
        $tvDuplicate = $service->purchase(
            $tvUser,
            $tvProduct->code,
            '1234567890',
            'tv-duplicate-key',
            ['contact_phone' => '08012345678'],
        );

        $this->assertSame('successful', $electricFirst->status);
        $this->assertSame($electricFirst->id, $electricDuplicate->id);
        $this->assertSame(150000, (int) $electricWallet->fresh()->available_balance_minor);
        $this->assertSame('successful', $tvFirst->status);
        $this->assertSame($tvFirst->id, $tvDuplicate->id);
        $this->assertSame(150000, (int) $tvWallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 2);
        $this->assertDatabaseCount('wallet_transactions', 4);
        $this->assertSame(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/merchant-verify'))->count());
        $this->assertSame(2, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
    }

    public function test_unverified_electricity_meter_cannot_create_or_debit_a_purchase_order(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        Http::fake(['https://sandbox.vtpass.com/api/merchant-verify' => Http::response([
            'code' => '099',
            'response_description' => 'Meter not found',
        ], 200)]);

        [$user, $wallet, $product] = $this->preparedCustomer(50000, 200000);
        $product->forceFill([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ])->save();

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '12345678901',
                'electricity-unverified-meter',
                ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'],
                50000,
            );
            $this->fail('An unverified meter must never proceed to a bill payment.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('could not confirm', $exception->getMessage());
        }

        $this->assertSame(200000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
    }

    public function test_ambiguous_tv_purchase_is_reconciled_without_resubmission_or_early_refund(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
        $responses = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'TV Holder']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'pending']]], 200)
            ->push(['code' => '015', 'response_description' => 'INVALID REQUEST ID'], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $responses]);

        [$user, $wallet, $product] = $this->preparedCustomer(50000, 200000);
        $product->forceFill([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ])->save();

        $service = app(VtuOrderService::class);
        $order = $service->purchase(
            $user,
            $product->code,
            '1234567890',
            'tv-ambiguous-outcome',
            ['contact_phone' => '08012345678'],
        );
        $this->assertSame('processing', $order->status);

        $reconciled = $service->reconcile($order->fresh());
        $duplicate = $service->purchase(
            $user,
            $product->code,
            '1234567890',
            'tv-ambiguous-outcome',
            ['contact_phone' => '08012345678'],
        );

        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame($order->id, $duplicate->id);
        $this->assertSame(150000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/requery'))->count());
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/merchant-verify'))->count());
    }

    public function test_reusing_tv_idempotency_key_for_a_different_smartcard_is_rejected_without_resubmission(): void
    {
        config([
            'services.vtu.driver' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);

        $responses = Http::sequence()
            ->push(['code' => '000', 'content' => ['Customer_Name' => 'TV Holder']], 200)
            ->push(['code' => '000', 'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'tv-idempotency-order']]], 200);
        Http::fake(['https://sandbox.vtpass.com/api/*' => $responses]);

        [$user, $wallet, $product] = $this->preparedCustomer(50000, 200000);
        $product->forceFill([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ])->save();

        $service = app(VtuOrderService::class);
        $first = $service->purchase(
            $user,
            $product->code,
            '1234567890',
            'tv-idempotency-payload-mismatch',
            ['contact_phone' => '08012345678'],
        );

        try {
            $service->purchase(
                $user,
                $product->code,
                '9876543210',
                'tv-idempotency-payload-mismatch',
                ['contact_phone' => '08012345678'],
            );
            $this->fail('An idempotency key must not be reused for a different smartcard.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            $this->assertStringContainsString('different purchase', $exception->getMessage());
        }

        $this->assertSame('successful', $first->status);
        $this->assertSame(150000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(2);
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/merchant-verify'))->count());
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/pay'))->count());
    }

    public function test_reserved_second_attempt_requeries_exact_provider_and_resubmits_same_identity_only_after_explicit_not_found(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['gsubz', 'sogo', 'dataway'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['gsubz'],
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
        ]);

        $sogoLookups = 0;
        $sogoSubmissions = 0;
        Http::fake(function ($request) use (&$sogoLookups, &$sogoSubmissions) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);

            if (str_contains($url, 'api.gsubz.com') && str_ends_with($path, '/api/pay/')) {
                return Http::response(['status' => 'pending', 'requestID' => $request['requestID']], 200);
            }

            if (str_contains($url, 'api.sogo.africa') && str_contains($path, '/transactions/')) {
                $sogoLookups++;
                return Http::response([], 404);
            }

            if (str_contains($url, 'api.sogo.africa') && str_contains($path, '/bills/airtime')) {
                $sogoSubmissions++;
                return Http::response([
                    'data' => ['status' => 'completed', 'reference' => 'SOGO-SECOND-ATTEMPT-RECOVERED'],
                ], 200);
            }

            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'gsubz' => ['network' => 'mtn'],
            'sogo' => ['network' => 'mtn'],
        ]]])->save();

        // Establish the original order/debit and simulate a worker crash after
        // alternate attempt #2 was durably reserved but before its first submit.
        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'reserved-second-sogo-recovery',
        );

        $firstAttempt = \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->where('attempt_number', 1)
            ->firstOrFail();
        $firstAttempt->forceFill([
            'provider' => 'gsubz',
            'outcome' => 'failed',
            'definitive_failure' => true,
            'failure_code' => 'provider_definitive_failure',
            'finished_at' => now(),
        ])->save();

        \Illuminate\Support\Facades\DB::table('vtu_orders')
            ->where('id', $order->getKey())
            ->update([
                'provider' => 'sogo',
                'status' => 'processing',
                'failure_code' => null,
                'updated_at' => now(),
            ]);
        $order = $order->fresh();

        $attempt = \App\Models\VtuProviderAttempt::query()->create([
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 2,
            'provider' => 'sogo',
            'outcome' => 'reserved',
            'definitive_failure' => false,
            'request_fingerprint' => hash('sha256', implode('|', [
                $order->public_id,
                'sogo',
                $order->product_code,
                $order->recipient,
                (string) $order->amount_minor,
            ])),
            'started_at' => now(),
        ]);

        $recovered = app(VtuOrderService::class)->reconcile($order->fresh());

        $this->assertSame('successful', $recovered->status);
        $this->assertSame('sogo', $recovered->provider);
        $this->assertSame('success', $attempt->fresh()->outcome);
        $this->assertSame(1, $sogoLookups, 'Recovery must look up the persisted second-attempt provider first.');
        $this->assertSame(1, $sogoSubmissions, 'Only one same-identity recovery submission is permitted.');
        $this->assertSame(2, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count(), 'Recovery must not reserve a third attempt.');
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'Recovery must not debit or refund again.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'GET'
            && str_contains($request->url(), '/transactions/'.$order->public_id));
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && $request->method() === 'POST'
            && str_contains($request->url(), '/bills/airtime')
            && $request->hasHeader('Idempotency-Key', $order->public_id));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dataway'));
    }

    public function test_reserved_second_attempt_recovers_by_querying_its_persisted_provider_without_third_submission_or_debit(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.failover_enabled' => true,
            'services.vtu.multi_provider.priority' => ['sogo', 'gsubz', 'dataway'],
            'services.vtu.multi_provider.definitive_failure_providers' => ['sogo'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);

        $gsubzSubmissions = 0;
        $gsubzLookups = 0;
        Http::fake(function ($request) use (&$gsubzSubmissions, &$gsubzLookups) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if (str_contains($request->url(), 'api.gsubz.com') && str_ends_with($path, '/api/pay/')) {
                $gsubzSubmissions++;

                return Http::response(['status' => 'success', 'reference' => 'UNEXPECTED-SUBMISSION'], 200);
            }

            if (str_contains($request->url(), 'api.gsubz.com') && str_ends_with($path, '/api/verify/')) {
                $gsubzLookups++;

                return Http::response(['status' => 'pending', 'requestID' => $request['requestID']], 200);
            }

            return Http::response(['status' => 'pending'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $product->forceFill(['metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
        ]]])->save();

        // Create one legitimate debited order without dispatching the alternate.
        // Then persist the crash checkpoint directly: attempt #1 failed
        // definitively, attempt #2 is reserved, and no GSUBZ submission occurred.
        $order = app(VtuOrderService::class)->purchase(
            $user,
            $product->code,
            '08012345678',
            'reserved-second-attempt-crash',
        );

        $firstAttempt = \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())
            ->where('attempt_number', 1)
            ->firstOrFail();
        $firstAttempt->forceFill([
            'provider' => 'sogo',
            'outcome' => 'failed',
            'definitive_failure' => true,
            'failure_code' => 'provider_definitive_failure',
            'finished_at' => now(),
        ])->save();

        // Update only persisted columns. purchase() leaves an internal
        // request-creation marker on the in-memory model; do not save it.
        \Illuminate\Support\Facades\DB::table('vtu_orders')
            ->where('id', $order->getKey())
            ->update([
                'provider' => 'gsubz',
                'status' => 'processing',
                'failure_code' => null,
                'updated_at' => now(),
            ]);
        $order = $order->fresh();

        \App\Models\VtuProviderAttempt::query()->create([
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 2,
            'provider' => 'gsubz',
            'outcome' => 'reserved',
            'definitive_failure' => false,
            'request_fingerprint' => hash('sha256', implode('|', [
                $order->public_id,
                'gsubz',
                $order->product_code,
                $order->recipient,
                (string) $order->amount_minor,
            ])),
            'started_at' => now(),
        ]);

        $recovered = app(VtuOrderService::class)->reconcile($order->fresh());

        $this->assertSame('awaiting_reconciliation', $recovered->status);
        $this->assertSame('gsubz', $recovered->provider);
        $this->assertDatabaseHas('vtu_provider_attempts', [
            'vtu_order_id' => $order->getKey(),
            'attempt_number' => 2,
            'provider' => 'gsubz',
            'outcome' => 'pending',
        ]);
        $this->assertSame(2, \App\Models\VtuProviderAttempt::query()
            ->where('vtu_order_id', $order->getKey())->count(), 'Recovery must never reserve a third attempt.');
        $this->assertSame(2, \App\Models\WalletTransaction::query()->count(), 'Recovery must not debit or refund again.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(0, $gsubzSubmissions, 'A pending lookup must not resubmit the reserved second attempt.');
        $this->assertSame(1, $gsubzLookups, 'Recovery must query the persisted alternate provider.');
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com')
            && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/api/verify/')
            && ($request['requestID'] ?? null) === $order->public_id);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && str_contains($request->url(), '/transactions/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'dataway'));
    }

    public function test_storefront_purchase_requires_a_published_store_and_a_product_enabled_for_that_store(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(500, 1500);
        $product->forceFill(['service_type' => 'data'])->save();

        $owner = User::query()->create([
            'name' => 'Store owner',
            'email' => 'store-owner-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);
        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Published reseller',
            'slug' => 'published-reseller-'.uniqid(),
            'type' => 'reseller',
            'status' => 'active',
        ]);
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id,
            'slug' => 'journey-store-'.uniqid(),
            'display_name' => 'Journey Store',
            'status' => 'draft',
            'enabled_services' => ['data'],
            'accent_color' => '#087443',
        ]);

        $provider = new class implements VtuProvider {
            public int $submissions = 0;
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function submit(VtuOrder $order): array { $this->submissions++; return ['status' => 'success']; }
            public function lookup(VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);

        try {
            app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'store-draft-purchase', [], null, $storefront->slug);
            $this->fail('Draft storefronts must never initiate purchases.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('not currently available', $exception->getMessage());
        }

        $storefront->update(['status' => 'published', 'enabled_services' => ['airtime']]);
        try {
            app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'store-disabled-product-type', [], null, $storefront->slug);
            $this->fail('Products outside the storefront enabled services must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('not enabled for this storefront', $exception->getMessage());
        }

        $storefront->update(['enabled_services' => ['data']]);
        $product->forceFill(['is_active' => false])->save();
        try {
            app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'store-inactive-product', [], null, $storefront->slug);
            $this->fail('Inactive products must never be purchasable through a storefront.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('no longer available', $exception->getMessage());
        }

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(0, $provider->submissions);
    }

    public function test_storefront_purchase_is_idempotent_and_commission_is_created_only_for_verified_success(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(500, 1500);
        $product->forceFill(['service_type' => 'data'])->save();

        $owner = User::query()->create([
            'name' => 'Commission beneficiary',
            'email' => 'commission-owner-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);
        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Commission reseller',
            'slug' => 'commission-reseller-'.uniqid(),
            'type' => 'reseller',
            'status' => 'active',
        ]);
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id,
            'slug' => 'commission-store-'.uniqid(),
            'display_name' => 'Commission Store',
            'status' => 'published',
            'enabled_services' => ['data'],
            'accent_color' => '#087443',
            'commission_rate_basis_points' => 500,
        ]);

        $provider = new class implements VtuProvider {
            public int $submissions = 0;
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function submit(VtuOrder $order): array { $this->submissions++; return ['status' => 'success', 'provider_reference' => 'verified-success']; }
            public function lookup(VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'store-idempotent-key', [], null, $storefront->slug);
        $duplicate = $service->purchase($user, $product->code, '08012345678', 'store-idempotent-key', [], null, $storefront->slug);

        $this->assertSame('successful', $order->status);
        $this->assertSame($order->getKey(), $duplicate->getKey());
        $this->assertSame($storefront->getKey(), (int) $order->fresh()->business_workspace_storefront_id);
        $this->assertSame(1, $provider->submissions, 'Duplicate submissions must not reach the provider twice.');
        $this->assertSame(1000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('business_workspace_commissions', 1);
        $commission = BusinessWorkspaceCommission::query()->firstOrFail();
        $this->assertSame($owner->getKey(), (int) $commission->beneficiary_user_id);
        $this->assertSame(25, (int) $commission->commission_amount_minor);
        $this->assertSame('accrued', $commission->status);

        $commissionService = app(\App\Services\Workspaces\StorefrontCommissionService::class);
        $settled = $commissionService->settle((int) $commission->getKey(), $user);
        $this->assertSame('settled', $settled->status);
        $this->assertSame(25, (int) $settled->beneficiary()->firstOrFail()->wallets()->where('currency', 'NGN')->value('available_balance_minor'));
        $commissionService->settle((int) $commission->getKey(), $user);
        $this->assertSame(25, (int) $settled->beneficiary()->firstOrFail()->wallets()->where('currency', 'NGN')->value('available_balance_minor'));
        $this->assertSame(1, \App\Models\WalletTransaction::query()->where('idempotency_key', 'reseller-commission:settlement:'.$commission->getKey())->count());
    }

    public function test_storefront_attribution_cannot_be_changed_by_reusing_the_same_idempotency_key_for_another_store(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(500, 1500);
        $product->forceFill(['service_type' => 'data'])->save();

        $stores = [];
        foreach (['first', 'second'] as $label) {
            $owner = User::query()->create([
                'name' => 'Store owner '.$label,
                'email' => 'store-'.$label.'-'.uniqid().'@example.test',
                'password' => 'test-password',
            ]);
            $workspace = BusinessWorkspace::query()->create([
                'owner_user_id' => $owner->id,
                'name' => 'Store '.$label,
                'slug' => 'store-workspace-'.$label.'-'.uniqid(),
                'type' => 'reseller',
                'status' => 'active',
            ]);
            $stores[] = BusinessWorkspaceStorefront::query()->create([
                'business_workspace_id' => $workspace->id,
                'slug' => 'store-'.$label.'-'.uniqid(),
                'display_name' => 'Store '.$label,
                'status' => 'published',
                'enabled_services' => ['data'],
                'accent_color' => '#087443',
            ]);
        }

        $provider = new class implements VtuProvider {
            public int $submissions = 0;
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function submit(VtuOrder $order): array { $this->submissions++; return ['status' => 'pending']; }
            public function lookup(VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);

        $service = app(VtuOrderService::class);
        $order = $service->purchase($user, $product->code, '08012345678', 'store-scope-idempotency', [], null, $stores[0]->slug);

        try {
            $service->purchase($user, $product->code, '08012345678', 'store-scope-idempotency', [], null, $stores[1]->slug);
            $this->fail('A request key cannot be reused to attribute the same purchase to another store.');
        } catch (\Symfony\Component\HttpKernel\Exception\ConflictHttpException $exception) {
            $this->assertStringContainsString('different purchase', $exception->getMessage());
        }

        $this->assertSame($stores[0]->getKey(), (int) $order->fresh()->business_workspace_storefront_id);
        $this->assertSame(1, $provider->submissions);
        $this->assertSame(1000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
    }

    public function test_failed_or_ambiguous_storefront_purchase_does_not_accrue_commission(): void
    {
        config([
            'services.vtu.name' => 'test_provider',
            'services.vtu.driver' => 'test',
            'services.vtu.multi_provider.definitive_failure_providers' => ['test_provider'],
        ]);
        [$user, $wallet, $product] = $this->preparedCustomer(500, 1500);
        $product->forceFill(['service_type' => 'data'])->save();

        $owner = User::query()->create([
            'name' => 'Failed commission owner',
            'email' => 'failed-commission-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);
        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Failed reseller',
            'slug' => 'failed-reseller-'.uniqid(),
            'type' => 'reseller',
            'status' => 'active',
        ]);
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id,
            'slug' => 'failed-store-'.uniqid(),
            'display_name' => 'Failed Store',
            'status' => 'published',
            'enabled_services' => ['data'],
            'accent_color' => '#087443',
            'commission_rate_basis_points' => 500,
        ]);

        $provider = new class implements VtuProvider {
            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}
            public function submit(VtuOrder $order): array { return ['status' => 'failed', 'definitive_failure' => true, 'message_code' => 'verified_failure']; }
            public function lookup(VtuOrder $order): array { return ['status' => 'pending']; }
        };
        $this->app->instance(VtuProvider::class, $provider);

        $order = app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'store-failed-purchase', [], null, $storefront->slug);

        $this->assertSame('failed', $order->status);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor, 'Definitive failure must refund the original debit exactly once.');
        $this->assertDatabaseCount('business_workspace_commissions', 0);
    }

    public function test_successful_reconciliation_accrues_storefront_commission_exactly_once(): void
    {
        config([
            'services.vtu.name' => 'test_provider',
            'services.vtu.driver' => 'test',
            'services.vtu.multi_provider.definitive_failure_providers' => [],
        ]);

        [$user, $wallet, $product] = $this->preparedCustomer(500, 1500);
        $product->forceFill(['service_type' => 'data'])->save();

        $owner = User::query()->create([
            'name' => 'Reconciled commission owner',
            'email' => 'reconciled-commission-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);
        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Reconciled reseller',
            'slug' => 'reconciled-reseller-'.uniqid(),
            'type' => 'reseller',
            'status' => 'active',
        ]);
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id,
            'slug' => 'reconciled-store-'.uniqid(),
            'display_name' => 'Reconciled Store',
            'status' => 'published',
            'enabled_services' => ['data'],
            'accent_color' => '#087443',
            'commission_rate_basis_points' => 500,
        ]);

        $provider = new class implements VtuProvider {
            public int $submissions = 0;
            public int $lookups = 0;

            public function assertConfigured(): void {}
            public function assertProductConfigured(VtuProduct $product): void {}

            public function submit(VtuOrder $order): array
            {
                $this->submissions++;

                return ['status' => 'pending'];
            }

            public function lookup(VtuOrder $order): array
            {
                $this->lookups++;

                return [
                    'status' => 'success',
                    'provider_reference' => 'reconciled-success-'.$order->public_id,
                ];
            }
        };
        $this->app->instance(VtuProvider::class, $provider);

        $service = app(VtuOrderService::class);
        $order = $service->purchase(
            $user,
            $product->code,
            '08012345678',
            'store-reconcile-commission-'.uniqid(),
            [],
            null,
            $storefront->slug,
        );

        $this->assertSame('processing', $order->status);
        $this->assertDatabaseCount('business_workspace_commissions', 0);

        $reconciled = $service->reconcile($order->fresh());
        $service->reconcile($reconciled->fresh());

        $this->assertSame('successful', $reconciled->fresh()->status);
        $this->assertSame(1, $provider->submissions, 'Reconciliation must not submit another purchase.');
        $this->assertSame(1, $provider->lookups, 'Terminal orders must not be looked up and settled twice.');
        $this->assertDatabaseCount('business_workspace_commissions', 1);
        $commission = BusinessWorkspaceCommission::query()->firstOrFail();
        $this->assertSame($order->getKey(), (int) $commission->vtu_order_id);
        $this->assertSame($owner->getKey(), (int) $commission->beneficiary_user_id);
        $this->assertSame(25, (int) $commission->commission_amount_minor);
        $this->assertSame('accrued', $commission->status);
        $this->assertSame(1000, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }

    private function preparedCustomer(int $productPriceMinor, int $initialBalanceMinor): array
    {
        $user = User::query()->create([
            'name' => 'VTU Test Customer',
            'email' => 'vtu-customer-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: $initialBalanceMinor,
            idempotencyKey: 'test-funding:'.$user->getKey(),
            reference: 'test-funding:'.$user->getKey(),
            description: 'Test-only wallet funding',
        );

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'test_network',
            'code' => 'test-airtime-'.uniqid(),
            'name' => 'Test Airtime',
            'price_minor' => $productPriceMinor,
            'face_value_minor' => $productPriceMinor,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        return [$user, $wallet->fresh(), $product];
    }

    public function test_customer_catalogue_returns_distinct_provider_options_without_leaking_routing_metadata(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['provider' => 'sogo', 'code' => 'sogo-mtn-sme-1gb-30d', 'provider_code' => 'sogo-secret-plan'],
            ['provider' => 'gsubz', 'code' => 'gsubz-mtn-sme-1gb-30d', 'provider_code' => 'gsubz-secret-plan'],
        ] as $entry) {
            VtuProduct::query()->create([
                'service_type' => 'data',
                'provider_key' => $entry['provider'],
                'provider_product_code' => $entry['provider_code'],
                'provider_cost_minor' => 25000,
                'network' => 'mtn',
                'code' => $entry['code'],
                'name' => 'MTN 1GB SME',
                'price_minor' => 30000,
                'currency' => 'NGN',
                'is_active' => true,
                'metadata' => [
                    'catalog_source' => $entry['provider'],
                    'purchase_approved' => true,
                    'provider_mappings' => [$entry['provider'] => ['plan_code' => $entry['provider_code']]],
                    'validity' => '30 days',
                    'data_type' => 'SME',
                    'internal_routing_note' => 'private routing detail',
                ],
            ]);
        }

        $response = $this->actingAs($user)
            ->getJson('/wallet/vtu/products?service_type=data')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $products = $response->json('data');
        $this->assertNotSame($products[0]['code'], $products[1]['code']);

        foreach ($products as $product) {
            $this->assertMatchesRegularExpression('/^p[0-9a-z]+\\.[a-f0-9]{32}$/', $product['code']);
            $this->assertSame('30 days', $product['metadata']['validity']);
            $this->assertSame('SME', $product['metadata']['data_type']);
            $this->assertArrayNotHasKey('provider_key', $product);
            $this->assertArrayNotHasKey('provider_product_code', $product);
            $this->assertArrayNotHasKey('provider_cost_minor', $product);
            $this->assertArrayNotHasKey('catalog_source', $product['metadata']);
            $this->assertArrayNotHasKey('provider_mappings', $product['metadata']);
            $this->assertArrayNotHasKey('internal_routing_note', $product['metadata']);
        }
    }

}
