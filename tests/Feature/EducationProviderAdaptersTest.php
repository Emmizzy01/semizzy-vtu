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
}
