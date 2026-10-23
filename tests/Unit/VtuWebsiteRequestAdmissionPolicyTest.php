<?php

namespace Tests\Unit;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Services\Addons\VtuWebsiteRequestAdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuWebsiteRequestAdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_request_addon_admits_only_registered_operations_when_enabled(): void
    {
        PlatformFeatureControl::query()->create([
            'feature_key' => 'service.vtu_website_requests',
            'label' => 'VTU website requests',
            'category' => 'Services',
            'owner' => 'Website requests',
            'description' => 'Addon admission policy regression fixture.',
            'is_enabled' => true,
            'is_implemented' => true,
        ]);
        PlatformAddon::query()->create([
            'addon_key' => 'service.vtu-website-requests',
            'name' => 'VTU website requests',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $policy = app(VtuWebsiteRequestAdmissionPolicy::class);

        $this->assertTrue($policy->isAvailable('vtu-website-requests.index'));
        $this->assertTrue($policy->isAvailable('vtu-website-requests.store'));
        $this->assertFalse($policy->isAvailable('vtu-website-requests.unregistered'));
    }

    public function test_website_request_admission_fails_closed_without_feature_control(): void
    {
        PlatformAddon::query()->create([
            'addon_key' => 'service.vtu-website-requests',
            'name' => 'VTU website requests',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $this->assertFalse(app(VtuWebsiteRequestAdmissionPolicy::class)->isAvailable('vtu-website-requests.store'));
    }
}
