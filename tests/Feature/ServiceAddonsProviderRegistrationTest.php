<?php

namespace Tests\Feature;

use App\Services\Addons\Providers\EducationServiceAddonProvider;
use App\Services\Addons\Providers\VtuServiceAddonProvider;
use App\Services\Vtu\MultiProviderVtuRouter;
use App\Services\Vtu\VtuProvider;
use Tests\TestCase;

class ServiceAddonsProviderRegistrationTest extends TestCase
{
    public function test_addon_owned_providers_are_registered_and_preserve_adapter_bindings(): void
    {
        $this->assertFalse(class_exists('App\\Providers\\ServiceAddonsProvider'));
        $this->assertNotEmpty(app()->getProviders(VtuServiceAddonProvider::class));
        $this->assertNotEmpty(app()->getProviders(EducationServiceAddonProvider::class));
        $this->assertTrue(app()->bound('education.providers.sogo'));
        $this->assertTrue(app()->bound('education.providers.gsubz'));
        $this->assertTrue(app()->bound('education.providers.vtpass'));
        $this->assertTrue(app()->bound('education.providers.dataway'));
        $this->assertTrue(app()->bound(VtuProvider::class));
        $this->assertInstanceOf(MultiProviderVtuRouter::class, app(VtuProvider::class));
    }
}
