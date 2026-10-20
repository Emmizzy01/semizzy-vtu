<?php

namespace Tests\Feature;

use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
use App\Models\VtuProduct;
use App\Services\Vtu\MultiProviderVtuRouter;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class MultiProviderVtuRouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.priority' => ['gsubz', 'sogo', 'dataway'],
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway-test-key',
        ]);
    }

    private function product(): VtuProduct
    {
        return new VtuProduct(['service_type' => 'airtime', 'metadata' => ['provider_mappings' => [
            'sogo' => ['network' => 'mtn'],
            'gsubz' => ['network' => 'mtn'],
            'dataway' => ['network' => 'mtn-airtime'],
        ]]]);
    }

    public function test_priority_selects_first_configured_and_mapped_provider_before_dispatch(): void
    {
        $this->assertSame('gsubz', app(MultiProviderVtuRouter::class)->providerNameForProduct($this->product()));
    }

    public function test_dataway_is_skipped_until_requery_contract_is_confirmed(): void
    {
        config(['services.vtu.multi_provider.priority' => ['dataway', 'sogo']]);
        $this->assertSame('sogo', app(MultiProviderVtuRouter::class)->providerNameForProduct($this->product()));
    }

    public function test_reconciliation_uses_persisted_provider_not_current_priority(): void
    {
        Http::fake(['api.sogo.africa/*' => Http::response([
            'data' => ['status' => 'completed', 'reference' => 'SOGO-ORDER-1'],
        ], 200)]);
        $order = new VtuOrder([
            'public_id' => '550e8400-e29b-41d4-a716-446655440000',
            'provider' => 'sogo',
            'status' => 'processing',
        ]);

        $result = app(MultiProviderVtuRouter::class)->lookup($order);
        $this->assertSame('success', $result['status']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa/v1/transactions/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com'));
    }

    public function test_stale_attempt_lookup_uses_that_attempts_provider_and_reference(): void
    {
        Http::fake([
            'api.sogo.africa/*' => Http::response([
                'data' => [
                    'status' => 'completed',
                    'reference' => 'SOGO-OLD-ATTEMPT-REF',
                ],
            ], 200),
            'api.gsubz.com/*' => Http::response([
                'status' => 'failed',
                'reference' => 'GSUBZ-NEW-ATTEMPT-REF',
            ], 200),
        ]);

        // The order has already advanced to GSubz attempt #2, but a late
        // reconciliation worker is still handling the persisted Sogo attempt #1.
        $order = new VtuOrder([
            'public_id' => '550e8400-e29b-41d4-a716-446655440001',
            'provider' => 'gsubz',
            'provider_reference' => 'GSUBZ-NEW-ATTEMPT-REF',
            'status' => 'processing',
        ]);
        $order->setAttribute('id', 77);

        $olderAttempt = new VtuProviderAttempt([
            'vtu_order_id' => 77,
            'attempt_number' => 1,
            'provider' => 'sogo',
            'provider_reference' => 'SOGO-OLD-ATTEMPT-REF',
            'outcome' => 'unknown',
        ]);

        $result = app(MultiProviderVtuRouter::class)->lookupAttempt($order, $olderAttempt);

        $this->assertSame('success', $result['status']);
        $this->assertSame('SOGO-OLD-ATTEMPT-REF', $result['provider_reference']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com'));
    }

    public function test_no_eligible_provider_fails_closed_without_http_request(): void
    {
        config(['services.vtu.multi_provider.priority' => ['dataway'], 'services.vtu.sogo.api_key' => null, 'services.vtu.gsubz.api_key' => null]);
        Http::fake();

        try {
            app(MultiProviderVtuRouter::class)->providerNameForProduct($this->product());
            $this->fail('Routing must fail closed when no provider is eligible.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('No configured', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_sogo_catalogue_product_routes_to_sogo_even_when_gsubz_has_higher_priority(): void
    {
        $product = new VtuProduct([
            'service_type' => 'data',
            'provider_key' => 'sogo',
            'metadata' => ['provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-1gb-7d'],
                'gsubz' => ['network' => 'mtn', 'plan_code' => 'gsubz-1gb-7d'],
            ]],
        ]);

        $this->assertSame('sogo', app(MultiProviderVtuRouter::class)->providerNameForProduct($product));
    }

    public function test_gsubz_catalogue_product_routes_to_gsubz_even_when_sogo_has_higher_priority(): void
    {
        config(['services.vtu.multi_provider.priority' => ['sogo', 'gsubz', 'dataway']]);
        $product = new VtuProduct([
            'service_type' => 'data',
            'provider_key' => 'gsubz',
            'metadata' => ['provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-1gb-7d'],
                'gsubz' => ['network' => 'mtn', 'plan_code' => 'gsubz-1gb-7d'],
            ]],
        ]);

        $this->assertSame('gsubz', app(MultiProviderVtuRouter::class)->providerNameForProduct($product));
    }

    public function test_dataway_catalogue_product_fails_closed_instead_of_falling_back_to_another_provider(): void
    {
        config(['services.vtu.multi_provider.priority' => ['sogo', 'gsubz', 'dataway']]);
        Http::fake();
        $product = new VtuProduct([
            'service_type' => 'data',
            'provider_key' => 'dataway',
            'metadata' => ['provider_mappings' => [
                'dataway' => ['network' => 'mtn', 'product_id' => 123],
                'sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-1gb-7d'],
                'gsubz' => ['network' => 'mtn', 'plan_code' => 'gsubz-1gb-7d'],
            ]],
        ]);

        try {
            app(MultiProviderVtuRouter::class)->providerNameForProduct($product);
            $this->fail('Dataway must remain blocked until its lookup and recovery contract is verified.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('Dataway purchases remain disabled', $exception->getMessage());
            Http::assertNothingSent();
        }
    }


    public function test_provider_key_remains_authoritative_when_catalog_source_metadata_conflicts(): void
    {
        config(['services.vtu.multi_provider.priority' => ['gsubz', 'sogo', 'dataway']]);
        $product = new VtuProduct([
            'service_type' => 'data',
            'provider_key' => 'sogo',
            'metadata' => [
                'catalog_source' => 'gsubz',
                'provider_mappings' => [
                    'sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-1gb-7d'],
                    'gsubz' => ['network' => 'mtn-data', 'plan_code' => 'gsubz-different-plan'],
                ],
            ],
        ]);

        // A stale or conflicting metadata label must never redirect this catalogue row.
        $this->assertSame('sogo', app(MultiProviderVtuRouter::class)->providerNameForProduct($product));
    }

    public function test_sogo_order_dispatches_with_its_own_variation_mapping(): void
    {
        Http::fake([
            'api.sogo.africa/v1/bills/data' => Http::response([
                'data' => ['status' => 'completed', 'reference' => 'SOGO-REF-1'],
            ], 200),
        ]);

        $product = new VtuProduct([
            'service_type' => 'data',
            'provider_key' => 'sogo',
            'metadata' => ['provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-1gb-7d'],
                'gsubz' => ['network' => 'mtn', 'plan_code' => 'gsubz-different-plan'],
            ]],
        ]);
        $order = new VtuOrder([
            'public_id' => 'sogo-order-dispatch-1',
            'provider' => 'sogo',
            'service_type' => 'data',
            'recipient' => '08012345678',
            'amount_minor' => 50000,
        ]);
        $order->setRelation('product', $product);

        $result = app(MultiProviderVtuRouter::class)->submit($order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('SOGO-REF-1', $result['provider_reference']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa/v1/bills/data')
            && data_get($request->data(), 'network') === 'mtn'
            && data_get($request->data(), 'variation_code') === 'sogo-1gb-7d');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com'));
    }

    public function test_gsubz_order_dispatches_with_its_own_plan_mapping(): void
    {
        Http::fake([
            'api.gsubz.com/api/pay/' => Http::response([
                'status' => 'success',
                'reference' => 'GSUBZ-REF-1',
            ], 200),
        ]);

        $product = new VtuProduct([
            'service_type' => 'data',
            'provider_key' => 'gsubz',
            'metadata' => ['provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'variation_code' => 'sogo-different-plan'],
                'gsubz' => ['network' => 'mtn-data', 'plan_code' => 'gsubz-1gb-7d'],
            ]],
        ]);
        $order = new VtuOrder([
            'public_id' => 'gsubz-order-dispatch-1',
            'provider' => 'gsubz',
            'service_type' => 'data',
            'recipient' => '08012345678',
            'amount_minor' => 50000,
        ]);
        $order->setRelation('product', $product);

        $result = app(MultiProviderVtuRouter::class)->submit($order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('GSUBZ-REF-1', $result['provider_reference']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com/api/pay/')
            && $request['serviceID'] === 'mtn-data'
            && $request['plan'] === 'gsubz-1gb-7d'
            && $request['requestID'] === 'gsubz-order-dispatch-1');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa'));
    }


    public function test_sogo_sandbox_not_found_does_not_authorize_same_key_resubmission(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
        ]);
        Http::fake(['sandbox.sogo.africa/*' => Http::response(['message' => 'not found'], 404)]);

        $order = new VtuOrder([
            'public_id' => 'sogo-sandbox-not-found-1',
            'provider' => 'sogo',
            'status' => 'processing',
        ]);

        $result = app(MultiProviderVtuRouter::class)->lookup($order);

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('not_found', $result['lookup_state']);
        $this->assertArrayNotHasKey('safe_to_submit_same_key', $result);
    }

    public function test_gsubz_verify_unknown_failure_code_remains_ambiguous(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
        ]);
        Http::fake(['api.gsubz.com/api/verify/*' => Http::response([
            'status' => 'failed',
            'code' => 500,
            'message' => 'upstream error',
        ], 200)]);

        $order = new VtuOrder([
            'public_id' => 'gsubz-ambiguous-verify-1',
            'provider' => 'gsubz',
            'status' => 'processing',
        ]);

        $result = app(MultiProviderVtuRouter::class)->lookup($order);

        $this->assertSame('failed', $result['status']);
        $this->assertFalse($result['definitive_failure']);
    }

}
