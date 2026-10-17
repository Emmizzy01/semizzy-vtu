<?php

namespace Tests\Feature;

use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\DatawayProviderClient;
use App\Services\Vtu\GsubzProviderClient;
use App\Services\Vtu\ProviderWebhookSignature;
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

    public function test_gsubz_uses_request_id_and_business_status_not_http_status(): void
    {
        Http::fake(['api.gsubz.com/*' => Http::response([
            'status' => 'failed', 'code' => 'FAILED', 'description' => 'not fulfilled',
        ], 200)]);

        $result = app(GsubzProviderClient::class)->submit($this->order());

        $this->assertSame('failed', $result['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/pay/')
            && $request['requestID'] === '550e8400-e29b-41d4-a716-446655440000');
    }

    public function test_dataway_requires_explicit_mapping_and_never_guesses_lookup_endpoint(): void
    {
        Http::fake(['dataway.ng/*' => Http::response(['status' => 'pending'], 202)]);

        $result = app(DatawayProviderClient::class)->submit($this->order());
        $this->assertSame('pending', $result['status']);

        try {
            app(DatawayProviderClient::class)->lookup($this->order());
            $this->fail('Dataway lookup must remain disabled until its contract is confirmed.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('lookup is not enabled', $exception->getMessage());
        }
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
