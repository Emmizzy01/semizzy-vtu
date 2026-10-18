<?php

namespace Tests\Feature;

use App\Models\EducationOrder;
use App\Models\EducationServiceProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use App\Services\Education\EducationProvider;
use App\Services\Education\Providers\DatawayEducationProvider;
use App\Services\Education\Providers\GsubzEducationProvider;
use App\Services\Education\Providers\SogoEducationProvider;
use App\Services\Education\Providers\VtpassEducationProvider;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class EducationProviderAdaptersTest extends TestCase
{
    use RefreshDatabase;
    public function test_only_order_owner_can_retrieve_completed_exam_pin_without_cache(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $order = \App\Models\EducationOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'wallet_id' => 1,
            'education_service_product_id' => 1,
            'idempotency_key' => 'test-delivery-'.Str::uuid(),
            'request_hash' => hash('sha256', 'delivery-test'),
            'provider' => 'gsubz',
            'status' => 'successful',
            'amount_minor' => 350000,
            'currency' => 'NGN',
            'candidate_details' => [],
            'provider_delivery_payload' => ['api_response' => 'TEST-EXAM-PIN-ONLY'],
            'completed_at' => now(),
        ]);

        $this->actingAs($otherUser)
            ->getJson(route('education-services.delivery', $order->public_id))
            ->assertNotFound();

        $deliveryResponse = $this->actingAs($owner)
            ->getJson(route('education-services.delivery', $order->public_id));

        $deliveryResponse->assertOk();
        $cacheControl = (string) $deliveryResponse->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $deliveryResponse->assertJsonPath('delivery.api_response', 'TEST-EXAM-PIN-ONLY');
    }

    public function test_exam_pin_delivery_is_not_returned_for_unfinished_orders(): void
    {
        $owner = User::factory()->create();
        $order = \App\Models\EducationOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $owner->id,
            'wallet_id' => 1,
            'education_service_product_id' => 1,
            'idempotency_key' => 'test-pending-delivery-'.Str::uuid(),
            'request_hash' => hash('sha256', 'pending-delivery-test'),
            'provider' => 'gsubz',
            'status' => 'pending',
            'amount_minor' => 350000,
            'currency' => 'NGN',
            'candidate_details' => [],
            'provider_delivery_payload' => ['api_response' => 'MUST-NOT-LEAK'],
        ]);

        $this->actingAs($owner)
            ->getJson(route('education-services.delivery', $order->public_id))
            ->assertStatus(409)
            ->assertDontSee('MUST-NOT-LEAK');
    }

    public function test_provider_adapters_are_registered_and_implement_the_contract(): void
    {
        foreach ([
            'sogo' => SogoEducationProvider::class,
            'gsubz' => GsubzEducationProvider::class,
            'vtpass' => VtpassEducationProvider::class,
            'dataway' => DatawayEducationProvider::class,
        ] as $key => $class) {
            $provider = app('education.providers.'.$key);
            $this->assertInstanceOf($class, $provider);
            $this->assertInstanceOf(EducationProvider::class, $provider);
        }
    }

    public function test_documented_providers_fail_closed_when_not_explicitly_enabled(): void
    {
        config([
            'services.education.providers.sogo.enabled' => false,
            'services.education.providers.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.education.providers.sogo.api_key' => 'sogo_sk_test_fake',
            'services.education.providers.gsubz.enabled' => false,
            'services.education.providers.gsubz.base_url' => 'https://api.gsubz.com',
            'services.education.providers.gsubz.api_key' => 'fake-gsubz-key',
            'services.education.providers.vtpass.enabled' => false,
            'services.education.providers.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.education.providers.vtpass.api_key' => 'fake-vtpass-key',
            'services.education.providers.vtpass.secret_key' => 'fake-vtpass-secret',
        ]);

        foreach ([
            app('education.providers.sogo'),
            app('education.providers.gsubz'),
            app('education.providers.vtpass'),
        ] as $provider) {
            try {
                $provider->assertConfigured();
                $this->fail('An education provider must remain disabled until merchant access is verified.');
            } catch (LogicException $exception) {
                $this->assertStringContainsString('disabled', strtolower($exception->getMessage()));
            }
        }
    }

    public function test_gsubz_exam_pin_submission_uses_persisted_order_reference_and_normalizes_success(): void
    {
        config([
            'services.education.providers.gsubz.enabled' => true,
            'services.education.providers.gsubz.base_url' => 'https://api.gsubz.com',
            'services.education.providers.gsubz.api_key' => 'test-only-key',
        ]);
        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'code' => 200,
                'status' => 'successful',
                'transactionID' => 98765,
                'api_response' => 'TEST-PIN-ONLY',
            ], 200),
        ]);

        $product = new EducationServiceProduct([
            'exam_body' => 'WAEC',
            'provider_product_code' => 'waec',
            'provider_cost_minor' => 350000,
            'metadata' => ['service_id' => 'waec', 'plan' => 'waecdirect'],
            'requires_candidate_validation' => false,
        ]);

        $result = app('education.providers.gsubz')->submit(
            'education-order-stable-reference',
            $product,
            ['phone' => '08031234567'],
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('98765', $result['provider_reference']);
        $this->assertSame(['api_response' => 'TEST-PIN-ONLY'], $result['delivery_payload']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/pay/')
            && ($request['requestID'] ?? null) === 'education-order-stable-reference'
            && ($request['serviceID'] ?? null) === 'waec'
            && ($request['plan'] ?? null) === 'waecdirect');
    }

    public function test_gsubz_unrecognized_failure_shape_remains_unknown_not_refunded(): void
    {
        config([
            'services.education.providers.gsubz.enabled' => true,
            'services.education.providers.gsubz.base_url' => 'https://api.gsubz.com',
            'services.education.providers.gsubz.api_key' => 'test-only-key',
        ]);
        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'code' => 500,
                'status' => 'error',
                'transactionID' => 98766,
            ], 200),
        ]);

        $product = new EducationServiceProduct([
            'exam_body' => 'NECO',
            'provider_product_code' => 'neco',
            'metadata' => ['service_id' => 'neco', 'plan' => 'neco'],
            'requires_candidate_validation' => false,
        ]);
        $result = app('education.providers.gsubz')->submit('education-order-uncertain', $product, ['phone' => '08031234567']);

        $this->assertSame('unknown', $result['status']);
    }

    public function test_gsubz_explicit_failed_status_is_a_definitive_failure(): void
    {
        config([
            'services.education.providers.gsubz.enabled' => true,
            'services.education.providers.gsubz.base_url' => 'https://api.gsubz.com',
            'services.education.providers.gsubz.api_key' => 'test-only-key',
        ]);
        Http::fake([
            'https://api.gsubz.com/api/pay/' => Http::response([
                'code' => 200,
                'status' => 'failed',
                'transactionID' => 98767,
            ], 200),
        ]);

        $product = new EducationServiceProduct([
            'exam_body' => 'NECO',
            'provider_product_code' => 'neco',
            'metadata' => ['service_id' => 'neco', 'plan' => 'neco'],
            'requires_candidate_validation' => false,
        ]);
        $result = app('education.providers.gsubz')->submit('education-order-definitive-failure', $product, ['phone' => '08031234567']);

        $this->assertSame('failed', $result['status']);
    }

    public function test_education_delivery_payload_is_encrypted_at_rest(): void
    {
        $order = new EducationOrder();
        $order->provider_delivery_payload = ['pin' => 'TEST-EXAM-PIN-12345'];
        $order->encrypted_candidate_details = ['profile_id' => 'TEST-CANDIDATE-12345'];

        $stored = (string) $order->getAttributes()['provider_delivery_payload'];
        $storedCandidate = (string) $order->getAttributes()['encrypted_candidate_details'];

        $this->assertStringNotContainsString('TEST-EXAM-PIN-12345', $stored);
        $this->assertStringNotContainsString('TEST-CANDIDATE-12345', $storedCandidate);
        $this->assertSame(['pin' => 'TEST-EXAM-PIN-12345'], $order->provider_delivery_payload);
        $this->assertSame(['profile_id' => 'TEST-CANDIDATE-12345'], $order->encrypted_candidate_details);
    }

    public function test_dataway_education_remains_blocked_until_its_contract_is_verified(): void
    {
        $provider = app('education.providers.dataway');
        $this->expectException(LogicException::class);
        $provider->assertConfigured();
    }
    public function test_sogo_sanitized_sandbox_response_normalizes_completed_purchase_and_pin(): void
    {
        config([
            'services.education.providers.sogo.enabled' => true,
            'services.education.providers.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.education.providers.sogo.api_key' => 'sogo_sk_test_sanitized',
        ]);
        Http::fake([
            'https://sandbox.sogo.africa/v1/bills/education' => Http::response([
                'data' => [
                    'status' => 'completed',
                    'reference' => 'SANDBOX-EDU-001',
                    'delivery' => ['pin' => 'SANITIZED-PIN-001', 'serial' => 'SANITIZED-SERIAL-001'],
                ],
            ], 200),
        ]);

        $product = new EducationServiceProduct([
            'exam_body' => 'WAEC',
            'provider_product_code' => 'waec-verified-variation',
            'provider_cost_minor' => 350000,
            'metadata' => ['service_type' => 'result_checker'],
            'requires_candidate_validation' => false,
        ]);
        $result = app('education.providers.sogo')->submit(
            'education-order-sogo-sandbox-001',
            $product,
            ['phone' => '08030000000'],
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('SANDBOX-EDU-001', $result['provider_reference']);
        $this->assertSame(['delivery' => ['pin' => 'SANITIZED-PIN-001', 'serial' => 'SANITIZED-SERIAL-001']], $result['delivery_payload']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/bills/education')
            && $request->hasHeader('Idempotency-Key', 'education-order-sogo-sandbox-001'));
    }

    public function test_sogo_candidate_validation_requires_positive_sanitized_confirmation(): void
    {
        config([
            'services.education.providers.sogo.enabled' => true,
            'services.education.providers.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.education.providers.sogo.api_key' => 'sogo_sk_test_sanitized',
        ]);
        Http::fake([
            'https://sandbox.sogo.africa/v1/bills/education/verify-jamb' => Http::response([
                'data' => ['verified' => true, 'candidate_name' => 'SANITIZED TEST CANDIDATE'],
            ], 200),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'JAMB',
            'provider_product_code' => 'jamb-verified-variation',
            'metadata' => [],
            'requires_candidate_validation' => true,
        ]);

        $result = app('education.providers.sogo')->validateCandidate($product, ['profile_id' => 'SANITIZED-PROFILE-001']);

        $this->assertSame('valid', $result['status']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/bills/education/verify-jamb'));
    }

    public function test_sogo_explicit_negative_jamb_verification_wins_over_returned_candidate_name(): void
    {
        config([
            'services.education.providers.sogo.enabled' => true,
            'services.education.providers.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.education.providers.sogo.api_key' => 'sogo_sk_test_sanitized',
        ]);
        Http::fake([
            'https://sandbox.sogo.africa/v1/bills/education/verify-jamb' => Http::response([
                'data' => ['verified' => false, 'candidate_name' => 'SANITIZED TEST CANDIDATE'],
            ], 200),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'JAMB',
            'provider_product_code' => 'jamb-verified-variation',
            'metadata' => [],
            'requires_candidate_validation' => true,
        ]);

        $result = app('education.providers.sogo')->validateCandidate($product, ['profile_id' => 'SANITIZED-PROFILE-NEGATIVE']);

        $this->assertSame('invalid', $result['status']);
    }

    public function test_sogo_candidate_name_without_positive_verification_remains_unknown(): void
    {
        config([
            'services.education.providers.sogo.enabled' => true,
            'services.education.providers.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.education.providers.sogo.api_key' => 'sogo_sk_test_sanitized',
        ]);
        Http::fake([
            'https://sandbox.sogo.africa/v1/bills/education/verify-jamb' => Http::response([
                'data' => ['candidate_name' => 'SANITIZED TEST CANDIDATE'],
            ], 200),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'JAMB',
            'provider_product_code' => 'jamb-verified-variation',
            'metadata' => [],
            'requires_candidate_validation' => true,
        ]);

        $result = app('education.providers.sogo')->validateCandidate($product, ['profile_id' => 'SANITIZED-PROFILE-UNKNOWN']);

        $this->assertSame('unknown', $result['status']);
    }

    public function test_vtpass_sanitized_sandbox_delivery_response_is_normalized(): void
    {
        config([
            'app.env' => 'testing',
            'services.education.providers.vtpass.enabled' => true,
            'services.education.providers.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.education.providers.vtpass.api_key' => 'SANITIZED-VTPASS-KEY',
            'services.education.providers.vtpass.secret_key' => 'SANITIZED-VTPASS-SECRET',
        ]);
        Http::fake([
            'https://sandbox.vtpass.com/api/pay' => Http::response([
                'code' => '000',
                'content' => ['transactions' => [
                    'status' => 'delivered',
                    'transactionId' => 'SANDBOX-VTPASS-EDU-001',
                ]],
                'cards' => [['pin' => 'SANITIZED-VTPASS-PIN', 'serial' => 'SANITIZED-VTPASS-SERIAL']],
                'purchased_code' => 'SANITIZED-VTPASS-PIN',
            ], 200),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'WAEC',
            'provider_product_code' => 'waec-verified-variation',
            'metadata' => ['provider_service_id' => 'waec', 'provider_variation_code' => 'waec-checker'],
            'requires_candidate_validation' => false,
        ]);

        $order = $this->createVtpassOrderReference();
        $result = app('education.providers.vtpass')->submit(
            $order->public_id,
            $product,
            ['phone' => '08030000000'],
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame('SANDBOX-VTPASS-EDU-001', $result['provider_reference']);
        $this->assertSame('SANITIZED-VTPASS-PIN', $result['delivery_payload']['purchased_code']);
        $expectedRequestId = $order->dispatch_started_at->copy()->timezone('Africa/Lagos')->format('YmdHi')
            .strtoupper(substr(hash('sha256', $order->public_id), 0, 20));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pay')
            && ($request['serviceID'] ?? null) === 'waec'
            && ($request['request_id'] ?? null) === $expectedRequestId
            && preg_match('/^\\d{12}[A-F0-9]{20}$/', (string) ($request['request_id'] ?? '')) === 1);
    }

    public function test_gsubz_lookup_uses_verify_endpoint_and_returns_sanitized_pin_payload(): void
    {
        config([
            'services.education.providers.gsubz.enabled' => true,
            'services.education.providers.gsubz.base_url' => 'https://api.gsubz.com',
            'services.education.providers.gsubz.api_key' => 'test-only-key',
        ]);
        Http::fake([
            'https://api.gsubz.com/api/verify/' => Http::response([
                'code' => 200,
                'status' => 'successful',
                'transactionID' => 'SANDBOX-GSUBZ-LOOKUP-001',
                'api_response' => 'SANITIZED-LOOKUP-PIN',
            ], 200),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'WAEC',
            'provider_product_code' => 'waec',
            'metadata' => ['service_id' => 'waec', 'plan' => 'waecdirect'],
            'requires_candidate_validation' => false,
        ]);

        $result = app('education.providers.gsubz')->lookup('education-order-lookup-001', $product);

        $this->assertSame('success', $result['status']);
        $this->assertSame('SANITIZED-LOOKUP-PIN', $result['delivery_payload']['api_response']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/verify/')
            && ($request['requestID'] ?? null) === 'education-order-lookup-001');
    }

    public function test_sogo_sandbox_lookup_404_remains_unknown_and_never_claims_safe_retry(): void
    {
        config([
            'services.education.providers.sogo.enabled' => true,
            'services.education.providers.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.education.providers.sogo.api_key' => 'sogo_sk_test_sanitized',
        ]);
        Http::fake([
            'https://sandbox.sogo.africa/v1/transactions/education-order-sogo-lookup-001*' => Http::response(['message' => 'not found'], 404),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'WAEC',
            'provider_product_code' => 'waec-verified-variation',
            'metadata' => ['service_type' => 'result_checker'],
            'requires_candidate_validation' => false,
        ]);

        $result = app('education.providers.sogo')->lookup('education-order-sogo-lookup-001', $product);

        $this->assertSame('unknown', $result['status']);
        $this->assertSame('sandbox_lookup_not_persisted', $result['message_code']);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/transactions/education-order-sogo-lookup-001'));
    }

    public function test_vtpass_lookup_uses_the_same_deterministic_request_id_and_normalizes_pin_delivery(): void
    {
        config([
            'app.env' => 'testing',
            'services.education.providers.vtpass.enabled' => true,
            'services.education.providers.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.education.providers.vtpass.api_key' => 'SANITIZED-VTPASS-KEY',
            'services.education.providers.vtpass.secret_key' => 'SANITIZED-VTPASS-SECRET',
        ]);
        Http::fake([
            'https://sandbox.vtpass.com/api/requery' => Http::response([
                'code' => '000',
                'content' => ['transactions' => [
                    'status' => 'delivered',
                    'transactionId' => 'SANDBOX-VTPASS-LOOKUP-001',
                ]],
                'cards' => [['pin' => 'SANITIZED-VTPASS-LOOKUP-PIN', 'serial' => 'SANITIZED-VTPASS-LOOKUP-SERIAL']],
                'purchased_code' => 'SANITIZED-VTPASS-LOOKUP-PIN',
            ], 200),
        ]);
        $product = new EducationServiceProduct([
            'exam_body' => 'WAEC',
            'provider_product_code' => 'waec-verified-variation',
            'metadata' => ['provider_service_id' => 'waec', 'provider_variation_code' => 'waec-checker'],
            'requires_candidate_validation' => false,
        ]);

        $order = $this->createVtpassOrderReference();
        $result = app('education.providers.vtpass')->lookup($order->public_id, $product);

        $this->assertSame('success', $result['status']);
        $this->assertSame('SANITIZED-VTPASS-LOOKUP-PIN', $result['delivery_payload']['purchased_code']);
        $expectedRequestId = $order->dispatch_started_at->copy()->timezone('Africa/Lagos')->format('YmdHi')
            .strtoupper(substr(hash('sha256', $order->public_id), 0, 20));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/requery')
            && ($request['request_id'] ?? null) === $expectedRequestId
            && preg_match('/^\\d{12}[A-F0-9]{20}$/', (string) ($request['request_id'] ?? '')) === 1);
    }

    private function createVtpassOrderReference(): EducationOrder
    {
        return EducationOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => 1,
            'wallet_id' => 1,
            'education_service_product_id' => 1,
            'idempotency_key' => 'vtpass-test-'.Str::uuid(),
            'request_hash' => hash('sha256', (string) Str::uuid()),
            'provider' => 'vtpass',
            'status' => 'processing',
            'amount_minor' => 350000,
            'currency' => 'NGN',
            'candidate_details' => [],
            'dispatch_started_at' => now(),
        ]);
    }

}
