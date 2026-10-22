<?php

namespace Tests\Unit;

use App\Services\Addons\AirtimePurchaseRuntime;
use App\Services\Addons\DataPurchaseRuntime;
use App\Services\Addons\ServicePurchaseRuntimeRegistry;
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
        $this->assertNull($registry->forService('electricity-bill'));
        $this->assertNull($registry->forService('tv-subscription'));
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

    public function test_data_runtime_uses_its_own_setting_before_legacy_fallback(): void
    {
        $runtime = app(DataPurchaseRuntime::class);
        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')->with('service.data.enabled', null)->once()->andReturn(true);
        $settings->shouldReceive('get')->with('service.airtime_data.enabled', false)->never();

        $this->assertNull($runtime->unavailableError($settings));
    }
}
