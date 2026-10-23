<?php

namespace Tests\Unit;

use App\Services\Addons\AirtimePurchaseRuntime;
use App\Services\Addons\DataPurchaseRuntime;
use App\Services\Addons\ElectricityPurchaseRuntime;
use App\Services\Addons\TvPurchaseRuntime;
use App\Services\Addons\ServicePurchaseRuntimeRegistry;
use App\Models\VtuProduct;
use App\Services\IntegrationSettings;
use Mockery;
use Tests\TestCase;

class ServicePurchaseRuntimeRegistryTest extends TestCase
{
    public function test_registry_resolves_separate_airtime_and_data_runtime_policies(): void
    {
        $registry = app(ServicePurchaseRuntimeRegistry::class);

        $this->assertInstanceOf(AirtimePurchaseRuntime::class, $registry->forService('airtime'));
        $this->assertInstanceOf(DataPurchaseRuntime::class, $registry->forService('data'));
        $this->assertInstanceOf(ElectricityPurchaseRuntime::class, $registry->forService('electricity-bill'));
        $this->assertInstanceOf(TvPurchaseRuntime::class, $registry->forService('tv-subscription'));
    }

    public function test_airtime_runtime_requires_amount_before_purchase_can_continue(): void
    {
        $runtime = app(AirtimePurchaseRuntime::class);

        $this->assertSame([
            'message' => 'Enter the airtime amount you want to purchase.',
            'status' => 422,
        ], $runtime->missingAmountError([]));
        $this->assertNull($runtime->missingAmountError(['amount' => 100]));
    }

    public function test_airtime_runtime_prefers_independent_setting_and_falls_back_to_legacy_setting(): void
    {
        $runtime = app(AirtimePurchaseRuntime::class);
        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')->with('service.airtime.enabled', null)->once()->andReturn(false);
        $settings->shouldReceive('get')->with('service.airtime_data.enabled', false)->never();

        $this->assertSame(503, $runtime->unavailableError($settings)['status']);

        $legacySettings = Mockery::mock(IntegrationSettings::class);
        $legacySettings->shouldReceive('get')->with('service.airtime.enabled', null)->once()->andReturn(null);
        $legacySettings->shouldReceive('get')->with('service.airtime_data.enabled', false)->once()->andReturn(true);
        $this->assertNull($runtime->unavailableError($legacySettings));
    }

    public function test_data_runtime_does_not_require_airtime_style_free_form_amount(): void
    {
        $this->assertNull(app(DataPurchaseRuntime::class)->missingAmountError([]));
    }

    public function test_data_runtime_falls_back_to_legacy_setting_only_when_independent_setting_is_absent(): void
    {
        $runtime = app(DataPurchaseRuntime::class);
        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')->with('service.data.enabled', null)->once()->andReturn(null);
        $settings->shouldReceive('get')->with('service.airtime_data.enabled', false)->once()->andReturn(false);

        $this->assertSame(503, $runtime->unavailableError($settings)['status']);
    }

    public function test_electricity_runtime_preserves_required_contact_meter_and_amount_checks(): void
    {
        $runtime = app(ElectricityPurchaseRuntime::class);

        $this->assertSame('Enter a valid contact phone number for the provider transaction.', $runtime->serviceInputError([])['message']);
        $this->assertSame('Select prepaid or postpaid for the electricity meter.', $runtime->serviceInputError(['contact_phone' => '08012345678'])['message']);
        $this->assertSame('Enter a valid electricity purchase amount.', $runtime->serviceInputError([
            'contact_phone' => '08012345678',
            'meter_type' => 'prepaid',
        ])['message']);
        $this->assertNull($runtime->serviceInputError([
            'contact_phone' => '08012345678',
            'meter_type' => 'prepaid',
            'amount' => 100,
        ]));
    }

    public function test_tv_runtime_requires_contact_phone_and_only_supports_bouquet_change(): void
    {
        $runtime = app(TvPurchaseRuntime::class);

        $this->assertSame('Enter a valid contact phone number for the provider transaction.', $runtime->serviceInputError([])['message']);
        $this->assertNull($runtime->serviceInputError([
            'contact_phone' => '08012345678',
            'subscription_type' => 'change',
        ]));
    }

    public function test_data_runtime_uses_its_own_setting_before_legacy_fallback(): void
    {
        $runtime = app(DataPurchaseRuntime::class);
        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')->with('service.data.enabled', null)->once()->andReturn(true);
        $settings->shouldReceive('get')->with('service.airtime_data.enabled', false)->never();

        $this->assertNull($runtime->unavailableError($settings));
    }

    public function test_electricity_runtime_rechecks_locked_catalogue_provider_limits(): void
    {
        $runtime = app(ElectricityPurchaseRuntime::class);
        $product = new VtuProduct([
            'service_type' => 'electricity-bill',
            'price_minor' => 10000,
            'metadata' => [
                'purchase_supported' => true,
                'provider_minimum_amount' => 500,
                'provider_maximum_amount' => 5000,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The electricity amount is outside the provider limits.');
        $runtime->prepareOrder($product, [
            'contact_phone' => '08012345678',
            'meter_type' => 'prepaid',
        ], 600000);
    }

    public function test_tv_runtime_uses_catalogue_price_and_rejects_unverified_renewal(): void
    {
        $runtime = app(TvPurchaseRuntime::class);
        $product = new VtuProduct([
            'service_type' => 'tv-subscription',
            'price_minor' => 125000,
            'metadata' => ['purchase_supported' => true],
        ]);

        $prepared = $runtime->prepareOrder($product, ['contact_phone' => '08012345678'], 1);
        $this->assertSame(125000, $prepared['amount_minor']);
        $this->assertSame('change', $prepared['service_details']['subscription_type']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only verified TV bouquet changes are available right now. Renewal is not enabled.');
        $runtime->prepareOrder($product, [
            'contact_phone' => '08012345678',
            'subscription_type' => 'renewal',
        ], 125000);
    }


    public function test_electricity_runtime_requires_verified_provider_meter_before_purchase(): void
    {
        $product = new VtuProduct([
            'service_type' => 'electricity-bill',
            'price_minor' => 10000,
            'metadata' => ['purchase_supported' => true],
        ]);
        $provider = Mockery::mock(VtuProvider::class, BillerVerificationProvider::class);
        $provider->shouldReceive('verifyElectricityMeter')
            ->once()
            ->with($product, '12345678901', 'prepaid')
            ->andReturn(['status' => 'verified']);

        app(ElectricityPurchaseRuntime::class)->verifyBeforePurchase(
            $product,
            '12345678901',
            ['meter_type' => 'prepaid'],
            $provider,
        );
    }

    public function test_electricity_runtime_rejects_unverified_meter_before_order_creation(): void
    {
        $product = new VtuProduct([
            'service_type' => 'electricity-bill',
            'price_minor' => 10000,
            'metadata' => ['purchase_supported' => true],
        ]);
        $provider = Mockery::mock(VtuProvider::class, BillerVerificationProvider::class);
        $provider->shouldReceive('verifyElectricityMeter')
            ->once()
            ->andReturn(['status' => 'unverified']);

        $this->expectException(\\InvalidArgumentException::class);
        $this->expectExceptionMessage('The provider could not confirm this meter. No purchase was created.');
        app(ElectricityPurchaseRuntime::class)->verifyBeforePurchase(
            $product,
            '12345678901',
            ['meter_type' => 'prepaid'],
            $provider,
        );
    }

    public function test_tv_runtime_requires_verified_provider_smartcard_before_purchase(): void
    {
        $product = new VtuProduct([
            'service_type' => 'tv-subscription',
            'price_minor' => 125000,
            'metadata' => ['purchase_supported' => true],
        ]);
        $provider = Mockery::mock(VtuProvider::class, BillerVerificationProvider::class);
        $provider->shouldReceive('verifyTvSmartcard')
            ->once()
            ->with($product, '123456789012')
            ->andReturn(['status' => 'verified']);

        app(TvPurchaseRuntime::class)->verifyBeforePurchase(
            $product,
            '123456789012',
            ['subscription_type' => 'change'],
            $provider,
        );
    }

}
