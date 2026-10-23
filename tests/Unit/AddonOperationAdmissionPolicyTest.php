<?php

namespace Tests\Unit;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Services\Addons\AddonOperationAdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddonOperationAdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_operation_requires_feature_addon_compatibility_and_registration(): void
    {
        PlatformFeatureControl::query()->create([
            'feature_key' => 'service.streaming_subscriptions',
            'label' => 'Streaming subscriptions',
            'category' => 'Entertainment',
            'owner' => 'Streaming addon',
            'description' => 'Shared admission regression fixture.',
            'is_enabled' => true,
            'is_implemented' => true,
        ]);
        PlatformAddon::query()->create([
            'addon_key' => 'service.streaming',
            'name' => 'Streaming subscriptions',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $policy = app(AddonOperationAdmissionPolicy::class);
        $catalogue = $policy->assess('service.streaming', 'service.streaming_subscriptions', 'streaming-subscriptions.index', true);
        $purchase = $policy->assess('service.streaming', 'service.streaming_subscriptions', 'streaming-subscriptions.purchase', false);

        $this->assertTrue($catalogue['available']);
        $this->assertFalse($purchase['available']);
        $this->assertFalse($purchase['addon_lifecycle']['operation_registered']);
    }

    public function test_missing_feature_control_fails_closed(): void
    {
        PlatformAddon::query()->create([
            'addon_key' => 'service.streaming',
            'name' => 'Streaming subscriptions',
            'version' => '1.0.0',
            'status' => 'enabled',
            'compatibility_status' => 'compatible',
        ]);

        $this->assertFalse(app(AddonOperationAdmissionPolicy::class)
            ->assess('service.streaming', 'service.streaming_subscriptions', 'streaming-subscriptions.index', true)['available']);
    }
}
