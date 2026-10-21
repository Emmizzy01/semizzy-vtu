<?php

namespace Tests\Feature;

use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\VtpassProviderClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtpassProviderClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
            // Adapter contract tests explicitly opt into purchase submission in test only.
            'services.vtu.vtpass.purchase_isolated' => false,
        ]);
    }

    public function test_isolation_gate_blocks_new_purchase_before_any_provider_request(): void
    {
        config(['services.vtu.vtpass.purchase_isolated' => true]);
        Http::fake();

        try {
            app(VtpassProviderClient::class)->submit($this->order('airtime', 'mtn'));
            $this->fail('VTpass purchases must remain blocked pending technical support confirmation.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('temporarily isolated', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_isolation_gate_keeps_existing_order_lookup_available(): void
    {
        config(['services.vtu.vtpass.purchase_isolated' => true]);
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => ['status' => 'pending']],
        ], 200));

        $result = app(VtpassProviderClient::class)->lookup($this->order('airtime', 'mtn'));

        $this->assertSame('pending', $result['status']);
        Http::assertSent(fn ($request) => $request->url() === 'https://sandbox.vtpass.com/api/requery');
    }

    public function test_order_service_type_must_match_validated_catalogue_product(): void
    {
        Http::fake();

        $order = $this->order('airtime', 'mtn');
        $order->service_type = 'data';

        try {
            app(VtpassProviderClient::class)->submit($order);
            $this->fail('An order must not submit a payload for a service type different from its catalogue product.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('does not match its catalogue product', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_production_rejects_sandbox_before_any_provider_request(): void
    {
        config(['app.env' => 'production']);
        Http::fake();

        try {
            app(VtpassProviderClient::class)->assertConfigured();
            $this->fail('Production must not submit purchases to the VTpass sandbox.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('sandbox is disabled in production', $exception->getMessage());
        }

        Http::assertNothingSent();
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
            'metadata' => ['purchase_approved' => true,
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

    public function test_data_service_with_unsupported_recipient_fields_is_blocked_even_if_product_is_active(): void
    {
        Http::fake();

        $order = $this->order('data', 'smile', [
            'is_active' => true,
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'smile-direct',
                'provider_variation_code' => 'smile-plan-1',
                'purchase_supported' => false,
                'recipient_type' => 'provider_specific',
            ],
        ]);

        try {
            app(VtpassProviderClient::class)->submit($order);
            $this->fail('A provider-specific data service must not be purchasable through the phone-only flow.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('provider-specific recipient validation', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_electricity_purchase_uses_meter_type_amount_and_contact_phone_payload(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'electricity-txn']],
        ], 200));

        $order = $this->order('electricity-bill', 'ikeja-electric', [
            'price_minor' => 500000,
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'ikeja-electric',
                'provider_minimum_amount' => 100,
                'provider_maximum_amount' => 100000,
                'purchase_supported' => true,
            ],
        ]);
        $order->recipient = '12345678901';
        $order->amount_minor = 500000;
        $order->service_details = ['meter_type' => 'prepaid', 'contact_phone' => '08012345678'];

        $result = app(VtpassProviderClient::class)->submit($order);

        $this->assertSame('success', $result['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pay')
            && $request['serviceID'] === 'ikeja-electric'
            && $request['billersCode'] === '12345678901'
            && $request['variation_code'] === 'prepaid'
            && $request['amount'] === 5000
            && $request['phone'] === '08012345678');
    }

    public function test_tv_purchase_uses_smartcard_variation_and_contact_phone(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'tv-txn']],
        ], 200));

        $order = $this->order('tv-subscription', 'dstv', [
            'price_minor' => 250000,
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ]);
        $order->recipient = '1234567890';
        $order->service_details = ['contact_phone' => '08012345678', 'subscription_type' => 'change'];

        $result = app(VtpassProviderClient::class)->submit($order);

        $this->assertSame('success', $result['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pay')
            && $request['serviceID'] === 'dstv'
            && $request['billersCode'] === '1234567890'
            && $request['variation_code'] === 'dstv-padi'
            && $request['phone'] === '08012345678'
            && $request['subscription_type'] === 'change'
            && ! array_key_exists('amount', $request->data()));
    }

    public function test_tv_purchase_rejects_renewal_without_verified_renewal_amount(): void
    {
        Http::fake();

        $order = $this->order('tv-subscription', 'dstv', [
            'price_minor' => 250000,
            'metadata' => ['purchase_approved' => true,
                'provider_service_id' => 'dstv',
                'provider_variation_code' => 'dstv-padi',
                'purchase_supported' => true,
            ],
        ]);
        $order->recipient = '1234567890';
        $order->service_details = ['contact_phone' => '08012345678', 'subscription_type' => 'renew'];

        try {
            app(VtpassProviderClient::class)->submit($order);
            $this->fail('TV renewal must stay blocked without the verified renewal amount.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Renewal remains disabled', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_electricity_and_tv_transaction_responses_map_failure_pending_and_ambiguous_safely(): void
    {
        $cases = [
            [
                'body' => ['code' => '016', 'response_description' => 'Transaction failed'],
                'expected' => 'failed',
            ],
            [
                'body' => ['code' => '000', 'content' => ['transactions' => ['status' => 'pending']]],
                'expected' => 'pending',
            ],
            [
                'body' => ['code' => '099', 'response_description' => 'Unknown provider outcome'],
                'expected' => 'pending',
            ],
            [
                'body' => ['code' => '000', 'content' => ['transactions' => ['status' => 'unrecognized']]],
                'expected' => 'pending',
            ],
        ];

        $responses = Http::fakeSequence();
        foreach (['electricity-bill', 'tv-subscription'] as $serviceType) {
            foreach ($cases as $index => $case) {
                $responses->push($case['body'], 200);

                $productOverrides = [
                    'metadata' => ['purchase_approved' => true,
                        'provider_service_id' => $serviceType === 'electricity-bill' ? 'ikeja-electric' : 'dstv',
                        'purchase_supported' => true,
                        'provider_minimum_amount' => 100,
                        'provider_maximum_amount' => 100000,
                        'provider_variation_code' => 'dstv-padi',
                    ],
                ];
                $order = $this->order($serviceType, $serviceType === 'electricity-bill' ? 'ikeja-electric' : 'dstv', $productOverrides);
                $order->recipient = $serviceType === 'electricity-bill' ? '12345678901' : '1234567890';
                $order->amount_minor = 50000;
                $order->service_details = [
                    'meter_type' => 'prepaid',
                    'contact_phone' => '08012345678',
                    ...($serviceType === 'tv-subscription' ? ['subscription_type' => 'change'] : []),
                ];

                $result = app(VtpassProviderClient::class)->submit($order);

                $this->assertSame(
                    $case['expected'],
                    $result['status'],
                    $serviceType.' response case '.$index.' must be mapped conservatively.',
                );
                Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pay'));
            }
        }
    }

    public function test_electricity_and_tv_products_without_purchase_approval_are_blocked_before_provider_request(): void
    {
        Http::fake();

        foreach ([
            $this->order('electricity-bill', 'ikeja-electric', [
                'metadata' => ['purchase_approved' => true,'provider_service_id' => 'ikeja-electric', 'purchase_supported' => false],
            ]),
            $this->order('tv-subscription', 'dstv', [
                'metadata' => ['purchase_approved' => true,'provider_service_id' => 'dstv', 'provider_variation_code' => 'dstv-padi', 'purchase_supported' => false],
            ]),
        ] as $order) {
            try {
                app(VtpassProviderClient::class)->submit($order);
                $this->fail('Unapproved biller products must not be submitted.');
            } catch (\LogicException $exception) {
                $this->assertStringContainsString('not been approved', $exception->getMessage());
            }
        }

        Http::assertNothingSent();
    }

    public function test_unknown_or_non_definitive_response_is_never_marked_failed(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '087',
            'response_description' => 'INVALID CREDENTIALS',
        ], 401));

        $result = app(VtpassProviderClient::class)->lookup($this->order('airtime', 'mtn'));

        $this->assertSame('unknown', $result['status']);
    }

    public function test_only_documented_definitive_vtpass_failure_codes_map_to_failed(): void
    {
        Http::fake(fn () => Http::response(['code' => '016', 'response_description' => 'TRANSACTION FAILED'], 200));

        $result = app(VtpassProviderClient::class)->lookup($this->order('airtime', 'mtn'));

        $this->assertSame('failed', $result['status']);
        $this->assertSame('016', $result['message_code']);
    }

    public function test_invalid_request_id_during_requery_remains_pending_not_failed(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '015',
            'response_description' => 'INVALID REQUEST ID',
        ], 200));

        $result = app(VtpassProviderClient::class)->lookup($this->order('airtime', 'mtn'));

        $this->assertSame('pending', $result['status']);
        $this->assertSame('015', $result['message_code']);
    }

    public function test_duplicate_request_id_response_is_not_misclassified_as_a_definitive_failure(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '014',
            'response_description' => 'REQUEST ID ALREADY EXIST',
        ], 200));

        $result = app(VtpassProviderClient::class)->submit($this->order('airtime', 'mtn'));

        $this->assertSame('pending', $result['status']);
        $this->assertSame('014', $result['message_code']);
    }

    public function test_purchase_and_requery_share_the_exact_same_persisted_request_id(): void
    {
        Http::fakeSequence()
            ->push([
                'code' => '000',
                'content' => ['transactions' => ['status' => 'pending']],
            ], 200)
            ->push([
                'code' => '000',
                'content' => ['transactions' => ['status' => 'delivered', 'transactionId' => 'vtpass-txn-stable']],
            ], 200);

        $order = $this->order('airtime', 'mtn');
        $order->created_at = Carbon::parse('2026-10-13 12:34:00', 'Africa/Lagos');
        $client = app(VtpassProviderClient::class);

        $submitted = $client->submit($order);
        $lookedUp = $client->lookup($order);

        $this->assertSame('pending', $submitted['status']);
        $this->assertSame('success', $lookedUp['status']);

        $requestIds = [];
        foreach (Http::recorded() as [$request, $response]) {
            $requestIds[] = $request['request_id'];
        }

        $this->assertCount(2, $requestIds);
        $this->assertSame($requestIds[0], $requestIds[1], 'VTpass requery must use the exact original purchase request_id.');
        $this->assertSame('20261013123412345678123412341234123456789abc', $requestIds[0]);
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
            && $request['request_id'] === '20261013123412345678123412341234123456789abc');
    }

    public function test_electricity_meter_verification_uses_service_id_and_prepaid_or_postpaid_type_without_payment(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => [
                'Customer_Name' => 'Verified Customer',
                'Customer_Address' => 'Lagos',
            ],
        ], 200));

        $product = new VtuProduct([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,'provider_service_id' => 'ikeja-electric'],
        ]);

        $result = app(VtpassProviderClient::class)->verifyElectricityMeter($product, '12345678901', 'prepaid');

        $this->assertSame('verified', $result['status']);
        $this->assertSame('Verified Customer', $result['customer_name']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/merchant-verify')
            && $request['serviceID'] === 'ikeja-electric'
            && $request['billersCode'] === '12345678901'
            && $request['type'] === 'prepaid'
            && ! array_key_exists('amount', $request->data())
            && ! str_ends_with($request->url(), '/pay'));
    }

    public function test_tv_smartcard_verification_does_not_send_electricity_type_or_purchase_amount(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['Customer_Name' => 'TV Subscriber'],
        ], 200));

        $product = new VtuProduct([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'metadata' => ['purchase_approved' => true,'provider_service_id' => 'dstv'],
        ]);

        $result = app(VtpassProviderClient::class)->verifyTvSmartcard($product, '1234567890');

        $this->assertSame('verified', $result['status']);
        $this->assertSame('TV Subscriber', $result['customer_name']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/merchant-verify')
            && $request['serviceID'] === 'dstv'
            && $request['billersCode'] === '1234567890'
            && ! array_key_exists('type', $request->data())
            && ! array_key_exists('amount', $request->data()));
    }

    public function test_biller_verification_rejects_wrong_product_type_and_invalid_meter_type_before_request(): void
    {
        Http::fake();
        $client = app(VtpassProviderClient::class);
        $tvProduct = new VtuProduct([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'metadata' => ['purchase_approved' => true,'provider_service_id' => 'dstv'],
        ]);
        $electricityProduct = new VtuProduct([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,'provider_service_id' => 'ikeja-electric'],
        ]);

        try {
            $client->verifyElectricityMeter($tvProduct, '1234567890', 'prepaid');
            $this->fail('A TV product must not be used for electricity meter verification.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('electricity product', $exception->getMessage());
        }

        try {
            $client->verifyElectricityMeter($electricityProduct, '1234567890', 'postpaid-bypass');
            $this->fail('Unknown electricity meter types must be rejected.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('prepaid or postpaid', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_non_successful_biller_verification_is_not_reported_as_verified(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '099',
            'response_description' => 'Customer not found',
        ], 200));

        $product = new VtuProduct([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'metadata' => ['purchase_approved' => true,'provider_service_id' => 'ikeja-electric'],
        ]);

        $result = app(VtpassProviderClient::class)->verifyElectricityMeter($product, '12345678901', 'postpaid');

        $this->assertSame('unverified', $result['status']);
        $this->assertSame('099', $result['message_code']);
        $this->assertNull($result['customer_name']);
    }

    public function test_legacy_untagged_product_without_explicit_approval_is_rejected(): void
    {
        Http::fake();
        $order = $this->order('airtime', 'mtn', [
            'metadata' => ['provider_service_id' => 'mtn'],
        ]);

        try {
            app(VtpassProviderClient::class)->submit($order);
            $this->fail('Legacy untagged products must require explicit purchase approval.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('sandbox verification', $exception->getMessage());
        }

        Http::assertNothingSent();
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
            'metadata' => ['purchase_approved' => true,],
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
