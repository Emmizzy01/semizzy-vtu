<?php

namespace Tests\Unit;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Services\Addons\EducationServiceAdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EducationServiceAdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_admission_requires_enabled_feature_addon_and_registered_operation(): void
    {
        PlatformFeatureControl::query()->create([
            'feature_key' => 'service.education',
            'label' => 'Education services',
            'category' => 'Services',
            'owner' => 'Education addon',
            'description' => 'Education admission regression fixture.',
            'is_enabled' => true,
            'is_implemented' => true,
        ]);
        PlatformAddon::query()->create([
            'addon_key' => 'service.education',
            'name' => 'Education services',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $policy = app(EducationServiceAdmissionPolicy::class);

        $this->assertTrue($policy->snapshot('education-services.index')['available']);
        $this->assertFalse($policy->snapshot('education-services.purchase')['available']);
    }

    public function test_catalogue_admission_fails_closed_when_feature_control_is_missing(): void
    {
        PlatformAddon::query()->create([
            'addon_key' => 'service.education',
            'name' => 'Education services',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $this->assertFalse(app(EducationServiceAdmissionPolicy::class)->snapshot('education-services.index')['available']);
    }
}
