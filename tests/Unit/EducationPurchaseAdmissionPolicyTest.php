<?php

namespace Tests\Unit;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Services\Addons\EducationPurchaseAdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EducationPurchaseAdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_stays_blocked_until_purchase_operation_is_registered(): void
    {
        PlatformFeatureControl::query()->create([
            'feature_key' => 'service.education',
            'label' => 'Education services',
            'category' => 'Services',
            'owner' => 'Education addon',
            'description' => 'Education purchase admission fixture.',
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

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('operation_unregistered');

        app(EducationPurchaseAdmissionPolicy::class)->assertAvailable();
    }

    public function test_missing_feature_control_fails_closed(): void
    {
        PlatformAddon::query()->create([
            'addon_key' => 'service.education',
            'name' => 'Education services',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $this->expectException(LogicException::class);
        app(EducationPurchaseAdmissionPolicy::class)->assertAvailable();
    }
}
