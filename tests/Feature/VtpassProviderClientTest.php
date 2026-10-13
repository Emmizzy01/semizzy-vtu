<?php

namespace Tests\\Feature;

use App\\Models\\VtuOrder;
use App\\Models\\VtuProduct;
use App\\Services\\Vtu\\VtpassProviderClient;
use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Facades\\Http;
use Tests\\TestCase;

class VtpassProviderClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
    }

    public function test_airtime_uses_official_vtpass_payload_and_auth_headers(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => [
                'status' => 'delivered',
                'transactionId' => 'vtpass-txn-123',
            ]],
        ], 200));

        $order = $this->order('airtime', 'mtn', [
            'face_value_minor' => 2000,
        ]);

        $result = app(VtpassProviderClient::class)->submit($order);

        $this->assertSame('success', $result['status']);
        $this->assertSame('vtpass-txn-123', $result['provider_reference']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://sandbox.vtpass.com/api/pay'
                && $request->hasHeader('api-key', 'sandbox-api-key')
                && $request->hasHeader('secret-key', 'sandbox-secret-key')
                && $request['serviceID'] === 'mtn'
                && $request['amount'] === 20
                && $request['phone'] === '08012345678'
                && strlen((string) $request['request_id']) >= 12
                && preg_match('/^[0-9]{12}/', (string) $request['request_id']) === 1;
        });
    }

    public function test_data_requires_verified_variation_code_and_maps_status_conservatively(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => ['status' => 'pending']],
        ], 200));

        $order = $this->order('data', 'mtn', [
            'metadata' => [
                'provider_service_id' => 'mtn-data',
                'provider_variation_code' => 'mtn-10mb-100',
            ],
        ]);

        $result = app(VtpassProviderClient::class)->submit($order);

        $this->assertSame('pending', $result['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/pay'
            && $request['serviceID'] === 'mtn-data'
            && $request['variation_code'] === 'mtn-10mb-100'
            && $request['billersCode'] === '08012345678');
    }

    public function test_unknown_or_non_definitive_response_is_never_marked_failed(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '087',
            'response_description' => 'INVALID CREDENTIALS',
        ], 401));

        $result = app(VtpassProviderClient::class)->lookup($this->order('airtime', 'mtn'));

        $this->assertSame('pending', $result['status']);
    }

    public function test_requery_uses_the_same_request_id_and_official_endpoint(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'vtpass-txn-lookup']],
        ], 200));

        $order = $this->order('airtime', 'mtn');
        $order->created_at = Carbon::parse('2026-10-13 12:34:00', 'Africa/Lagos');
        $order->public_id = '12345678-1234-1234-1234-123456789abc';

        $result = app(VtpassProviderClient::class)->lookup($order);

        $this->assertSame('success', $result['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/requery'
            && $request['request_id'] === '202610131234123456781234123412341234123456789abc');
    }

    private function order(string $serviceType, string $network, array $productOverrides = []): VtuOrder
    {
        $product = new VtuProduct(array_merge([
            'service_type' => $serviceType,
            'network' => $network,
            'code' => 'test-product',
            'name' => 'Test Product',
            'price_minor' => 2000,
            'face_value_minor' => 2000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => [],
        ], $productOverrides));

        $order = new VtuOrder([
            'public_id' => '12345678-1234-1234-1234-123456789abc',
            'service_type' => $serviceType,
            'network' => $network,
            'recipient' => '08012345678',
            'amount_minor' => 2000,
            'currency' => 'NGN',
            'created_at' => Carbon::parse('2026-10-13 12:34:00', 'Africa/Lagos'),
        ]);
        $order->setRelation('product', $product);

        return $order;
    }
}
