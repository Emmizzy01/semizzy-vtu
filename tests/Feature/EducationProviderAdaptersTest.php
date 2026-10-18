<?php

namespace Tests\Feature;

use App\Models\EducationServiceProduct;
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
                'status' => 'failed',
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

    public function test_dataway_education_remains_blocked_until_its_contract_is_verified(): void
    {
        $provider = app('education.providers.dataway');
        $this->expectException(LogicException::class);
        $provider->assertConfigured();
    }
}
