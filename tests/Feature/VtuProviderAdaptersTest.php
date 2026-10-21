<?php

namespace Tests\Feature;

use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
use App\Models\VtuProduct;
use App\Services\Vtu\DatawayProviderClient;
use App\Services\Vtu\GsubzProviderClient;
use App\Services\Vtu\MultiProviderVtuRouter;
use App\Services\Vtu\ProviderWebhookSignature;
use App\Services\IntegrationSettings;
use App\Services\Vtu\SogoProviderClient;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class VtuProviderAdaptersTest extends TestCase
{
    private function order(string $serviceType = 'airtime'): VtuOrder
    {
        $product = new VtuProduct([
            'service_type' => $serviceType,
            'metadata' => ['provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'plan_code' => 'plan-1'],
                'gsubz' => ['network' => 'mtn', 'plan_code' => 'plan-1'],
                'dataway' => ['network' => 'mtn-airtime', 'product_id' => 42],
            ]],
        ]);

        $order = new VtuOrder([
            'public_id' => '550e8400-e29b-41d4-a716-446655440000',
            'recipient' => '08012345678',
            'amount_minor' => 10000,
            'service_type' => $serviceType,
        ]);
        $order->setRelation('product', $product);

        return $order;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway-test-key',
        ]);
    }

    public function test_mapped_provider_clients_do_not_follow_redirects_to_untrusted_hosts(): void
    {
        Http::fake(fn () => Http::response('', 302, [
            'Location' => 'https://unexpected.example/collect',
        ]));

        $order = $this->order();
        $results = [
            app(SogoProviderClient::class)->submit($order),
            app(GsubzProviderClient::class)->submit($order),
            app(DatawayProviderClient::class)->submit($order),
        ];

        foreach ($results as $result) {
            $this->assertSame('unknown', $result['status']);
        }

        Http::assertNotSent(fn ($request) => $request->url() === 'https://unexpected.example/collect');
        $this->assertCount(3, Http::recorded());
    }

    public function test_sogo_uses_stable_idempotency_key_and_normalizes_success(): void
    {
        Http::fake(['api.sogo.africa/*' => Http::response([
            'data' => ['status' => 'completed', 'reference' => 'SOGO-123'],
        ], 201)]);

        $result = app(SogoProviderClient::class)->submit($this->order());

        $this->assertSame('success', $result['status']);
        $this->assertSame('SOGO-123', $result['provider_reference']);
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440000')
            && str_ends_with($request->url(), '/bills/airtime'));
    }

    public function test_sogo_only_marks_documented_final_failure_and_refund_as_definitive(): void
    {
        Http::fakeSequence()
            ->push(['data' => ['status' => 'failed', 'reference' => 'SOGO-FAILED']], 201)
            ->push(['data' => ['status' => 'refunded', 'reference' => 'SOGO-REFUNDED']], 201)
            ->push(['data' => ['status' => 'processing', 'reference' => 'SOGO-PENDING']], 201)
            ->push(['data' => ['status' => 'cancelled', 'reference' => 'SOGO-CANCELLED']], 201);

        $client = app(SogoProviderClient::class);

        $failed = $client->submit($this->order());
        $refunded = $client->submit($this->order());
        $processing = $client->submit($this->order());
        $cancelled = $client->submit($this->order());

        $this->assertSame('failed', $failed['status']);
        $this->assertTrue($failed['definitive_failure']);
        $this->assertSame('failed', $refunded['status']);
        $this->assertTrue($refunded['definitive_failure']);
        $this->assertSame('pending', $processing['status']);
        $this->assertFalse($processing['definitive_failure']);
        $this->assertSame('failed', $cancelled['status']);
        $this->assertFalse($cancelled['definitive_failure']);
    }

    public function test_sogo_404_lookup_exposes_only_the_documented_same_key_recovery_signal(): void
    {
        Http::fake(['api.sogo.africa/*' => Http::response(['message' => 'not found'], 404)]);

        $result = app(SogoProviderClient::class)->lookup($this->order());

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('not_found', $result['lookup_state']);
        $this->assertTrue($result['safe_to_submit_same_key']);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/transactions/550e8400-e29b-41d4-a716-446655440000'));
    }

    public function test_sogo_sandbox_404_does_not_authorize_resubmission(): void
    {
        config(['services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1']);
        Http::fake(['sandbox.sogo.africa/*' => Http::response(['message' => 'not found'], 404)]);

        $result = app(SogoProviderClient::class)->lookup($this->order());

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('not_found', $result['lookup_state']);
        $this->assertArrayNotHasKey('safe_to_submit_same_key', $result);
    }

    public function test_sogo_custom_non_live_host_404_does_not_authorize_resubmission(): void
    {
        config(['services.vtu.sogo.base_url' => 'https://staging.sogo.example/v1']);
        Http::fake(['staging.sogo.example/*' => Http::response(['message' => 'not found'], 404)]);

        $result = app(SogoProviderClient::class)->lookup($this->order());

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('not_found', $result['lookup_state']);
        $this->assertArrayNotHasKey('safe_to_submit_same_key', $result);
    }

    public function test_sogo_non_404_lookup_errors_do_not_claim_safe_resubmission(): void
    {
        Http::fake(['api.sogo.africa/*' => Http::response(['message' => 'temporarily unavailable'], 503)]);

        $result = app(SogoProviderClient::class)->lookup($this->order());

        $this->assertSame('unknown', $result['status']);
        $this->assertArrayNotHasKey('safe_to_submit_same_key', $result);
    }

    public function test_reconciliation_uses_the_provider_recorded_on_the_specific_attempt(): void
    {
        Http::fake([
            'api.sogo.africa/*' => Http::response(['data' => ['status' => 'completed', 'reference' => 'SOGO-RECOVERED']], 200),
            'api.gsubz.com/*' => Http::response(['status' => 'pending'], 200),
        ]);

        $order = $this->order();
        $order->provider = 'gsubz';
        $order->setAttribute('id', 41);
        $attempt = new VtuProviderAttempt([
            'vtu_order_id' => 41,
            'attempt_number' => 1,
            'provider' => 'sogo',
            'outcome' => 'unknown',
        ]);

        $result = app(MultiProviderVtuRouter::class)->lookupAttempt($order, $attempt);

        $this->assertSame('success', $result['status']);
        $this->assertSame('SOGO-RECOVERED', $result['provider_reference']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.sogo.africa')
            && str_contains($request->url(), '/transactions/550e8400-e29b-41d4-a716-446655440000'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.gsubz.com'));
    }

    public function test_gsubz_uses_request_id_and_business_status_not_http_status(): void
    {
        Http::fake(['api.gsubz.com/*' => Http::response([
            'status' => 'failed', 'code' => '200', 'description' => 'not fulfilled',
        ], 200)]);

        $result = app(GsubzProviderClient::class)->submit($this->order());

        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['definitive_failure'], 'GSUBZ documents a successful HTTP response with status=failed as a purchase that did not go through.');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/pay/')
            && $request['requestID'] === '550e8400-e29b-41d4-a716-446655440000');
    }

    public function test_gsubz_transient_and_unknown_failure_codes_are_not_definitive(): void
    {
        Http::fakeSequence()
            ->push(['code' => '429', 'status' => 'failed', 'description' => 'TOO_MANY_REQUESTS'], 200)
            ->push(['code' => '500', 'status' => 'failed', 'description' => 'SERVER_ERROR'], 200)
            ->push(['code' => '502', 'status' => 'failed', 'description' => 'GATEWAY_ERROR'], 200)
            ->push(['code' => '999', 'status' => 'failed', 'description' => 'UNKNOWN_ERROR'], 200)
            ->push(['code' => '429', 'status' => 'failed', 'description' => 'TOO_MANY_REQUESTS'], 200)
            ->push(['code' => '500', 'status' => 'failed', 'description' => 'SERVER_ERROR'], 200)
            ->push(['code' => '502', 'status' => 'failed', 'description' => 'GATEWAY_ERROR'], 200)
            ->push(['code' => '999', 'status' => 'failed', 'description' => 'UNKNOWN_ERROR'], 200);

        $client = app(GsubzProviderClient::class);
        foreach (range(1, 4) as $_) {
            $result = $client->submit($this->order());
            $this->assertSame('failed', $result['status']);
            $this->assertFalse($result['definitive_failure']);
        }
        foreach (range(1, 4) as $_) {
            $result = $client->lookup($this->order());
            $this->assertSame('failed', $result['status']);
            $this->assertFalse($result['definitive_failure']);
        }
    }

    public function test_gsubz_lookup_uses_documented_verify_endpoint_and_request_id_status(): void
    {
        Http::fakeSequence()
            ->push([
                'code' => '200',
                'status' => 'success',
                'description' => 'TRANSACTION_SUCCESSFUL',
                'api_response' => 'Transaction completed.',
            ], 200)
            ->push([
                'code' => '200',
                'status' => 'failed',
                'description' => 'TRANSACTION_FAILED',
                'api_response' => 'Transaction failed.',
            ], 200);

        $client = app(GsubzProviderClient::class);
        $order = $this->order();

        $success = $client->lookup($order);
        $failure = $client->lookup($order);

        $this->assertSame('success', $success['status']);
        $this->assertFalse($success['definitive_failure']);
        $this->assertSame('failed', $failure['status']);
        $this->assertTrue($failure['definitive_failure'], 'Verified GSUBZ status=failed is the documented final non-delivery outcome.');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/verify/')
            && $request->method() === 'POST'
            && $request['requestID'] === $order->public_id
            && $request['api'] === 'gsubz-test-key');
        $this->assertCount(2, Http::recorded());
    }


    public function test_gsubz_lookup_uses_admin_configured_integration_key(): void
    {
        $settings = \Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')->andReturnUsing(
            fn (string $key, mixed $fallback = null) => $key === 'vtu.gsubz.api_key'
                ? 'admin-configured-gsubz-key'
                : $fallback,
        );
        app()->instance(IntegrationSettings::class, $settings);

        Http::fake(['api.gsubz.com/*' => Http::response([
            'code' => '200',
            'status' => 'success',
            'description' => 'TRANSACTION_SUCCESSFUL',
        ], 200)]);

        $result = app(GsubzProviderClient::class)->lookup($this->order());

        $this->assertSame('success', $result['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/verify/')
            && $request['api'] === 'admin-configured-gsubz-key');
    }

    public function test_sogo_and_gsubz_lookup_unknown_statuses_never_authorize_failure_recovery(): void
    {
        Http::fakeSequence()
            ->push(['data' => ['status' => 'queued_for_processing', 'reference' => 'SOGO-UNKNOWN']], 200)
            ->push(['status' => 'failed', 'description' => 'temporarily unavailable'], 503)
            ->push(['status' => 'queued_for_processing', 'reference' => 'GSUBZ-UNKNOWN'], 200)
            ->push(['status' => 'failed', 'description' => 'temporarily unavailable'], 503);

        $order = $this->order();
        $sogo = app(SogoProviderClient::class);
        $gsubz = app(GsubzProviderClient::class);

        $results = [
            $sogo->lookup($order),
            $sogo->lookup($order),
            $gsubz->lookup($order),
            $gsubz->lookup($order),
        ];

        foreach ($results as $result) {
            $this->assertSame('unknown', $result['status']);
            $this->assertArrayNotHasKey('safe_to_submit_same_key', $result);
            $this->assertFalse((bool) ($result['definitive_failure'] ?? false));
        }

        $this->assertCount(4, Http::recorded());
    }

    public function test_dataway_data_purchase_rejects_unverified_product_ids_before_http_dispatch(): void
    {
        Http::fake();

        $order = $this->order('data');
        $product = $order->product;
        $product->metadata = ['provider_mappings' => [
            'dataway' => ['product_id' => 'unverified-plan-code'],
        ]];
        $order->setRelation('product', $product);

        try {
            app(DatawayProviderClient::class)->submit($order);
            $this->fail('Dataway data purchases must require a verified positive integer product_id.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('verified positive integer product_id', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_dataway_data_purchase_rejects_non_positive_or_fractional_product_ids_before_http_dispatch(): void
    {
        Http::fake();

        foreach ([0, -1, 1.5, '0', '-2', '42.5'] as $invalidProductId) {
            $order = $this->order('data');
            $product = $order->product;
            $product->metadata = ['provider_mappings' => [
                'dataway' => ['product_id' => $invalidProductId],
            ]];
            $order->setRelation('product', $product);

            try {
                app(DatawayProviderClient::class)->submit($order);
                $this->fail('Dataway must reject non-positive or fractional product IDs before dispatch.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('verified positive integer product_id', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_dataway_pending_duplicate_replay_keeps_the_same_reference_and_never_claims_safe_recovery(): void
    {
        Http::fakeSequence()
            ->push([
                'status' => 'pending',
                'reference' => 'DATAWAY-TXN-1',
            ], 202)
            ->push([
                'status' => 'pending',
                'code' => 'DUPLICATE',
                'duplicate' => true,
                'reference' => 'DATAWAY-TXN-1',
                'message' => 'Your previous request is still being processed.',
            ], 202);

        $order = $this->order();
        $client = app(DatawayProviderClient::class);

        $first = $client->submit($order);
        $duplicate = $client->submit($order);

        $this->assertSame('pending', $first['status']);
        $this->assertSame('pending', $duplicate['status']);
        $this->assertArrayNotHasKey('definitive_failure', $duplicate);
        $this->assertArrayNotHasKey('safe_to_submit_same_key', $duplicate);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/airtime/purchase/')
            && $request['reference'] === $order->public_id);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/airtime/purchase/')
            && $request['reference'] === $order->public_id);
        $this->assertCount(2, Http::recorded());

        try {
            $client->lookup($order);
            $this->fail('Dataway lookup must remain disabled until an approved reconciliation contract exists.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('lookup is not enabled', $exception->getMessage());
        }
    }

    public function test_dataway_is_excluded_from_automatic_failover_until_requery_contract_is_confirmed(): void
    {
        config([
            'services.vtu.multi_provider.enabled' => true,
            'services.vtu.multi_provider.priority' => ['dataway', 'gsubz', 'sogo'],
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz-test-key',
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-test-key',
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway-test-key',
        ]);
        Http::fake();

        $order = $this->order();
        $order->setAttribute('id', 41);
        $attempt = new VtuProviderAttempt([
            'vtu_order_id' => 41,
            'attempt_number' => 1,
            'provider' => 'sogo',
            'outcome' => 'failed',
        ]);

        $next = app(MultiProviderVtuRouter::class)->nextProviderForAttempt($order, $attempt);

        $this->assertSame('gsubz', $next, 'Router must skip Dataway even when it has the highest configured priority.');
        Http::assertNothingSent();
    }


    public function test_dataway_http_errors_and_unrecognized_statuses_remain_ambiguous(): void
    {
        Http::fakeSequence()
            ->push(['message' => 'temporary outage'], 503)
            ->push(['status' => 'queued_for_processing', 'reference' => 'DATAWAY-TXN-2'], 200);

        $client = app(DatawayProviderClient::class);
        $order = $this->order();

        $httpError = $client->submit($order);
        $unrecognized = $client->submit($order);

        foreach ([$httpError, $unrecognized] as $result) {
            $this->assertSame('unknown', $result['status']);
            $this->assertArrayNotHasKey('definitive_failure', $result);
            $this->assertArrayNotHasKey('safe_to_submit_same_key', $result);
        }

        $this->assertCount(2, Http::recorded());
    }

    public function test_sogo_and_dataway_signatures_use_raw_body_and_reject_tampering(): void
    {
        $body = '{"event":"transaction.successful","data":{"reference":"TXN-1"}}';
        $secret = 'webhook-test-secret';
        $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
        $verifier = app(ProviderWebhookSignature::class);

        $this->assertTrue($verifier->verifySogo($body, $signature, $secret));
        $this->assertFalse($verifier->verifySogo($body.' ', $signature, $secret));
        $this->assertTrue($verifier->verifyDataway($body, $signature, $secret));
        $this->assertFalse($verifier->verifyDataway($body.' ', $signature, $secret));
        $this->assertFalse($verifier->verifyDataway($body, $signature, ''));
    }

    public function test_gsubz_signature_binds_timestamp_and_enforces_replay_window(): void
    {
        $body = '{"event":"webhook.test","eventId":"evt-1"}';
        $secret = 'gsubz-webhook-test-secret';
        $timestamp = '1792243200';
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
        $verifier = app(ProviderWebhookSignature::class);

        $this->assertTrue($verifier->verifyGsubz($body, $timestamp, $signature, $secret, 1792243200));
        $this->assertFalse($verifier->verifyGsubz($body, $timestamp, $signature, $secret, 1792243601));
        $this->assertFalse($verifier->verifyGsubz($body.' ', $timestamp, $signature, $secret, 1792243200));
        $this->assertFalse($verifier->verifyGsubz($body, 'not-a-timestamp', $signature, $secret, 1792243200));
    }
}
