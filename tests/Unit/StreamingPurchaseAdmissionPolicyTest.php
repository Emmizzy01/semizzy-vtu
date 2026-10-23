<?php

namespace Tests\Unit;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Services\Addons\StreamingPurchaseAdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamingPurchaseAdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_streaming_catalogue_registration_does_not_enable_customer_purchases(): void
    {
        PlatformFeatureControl::query()->create([
            'feature_key' => 'service.streaming_subscriptions',
            'label' => 'Streaming subscriptions',
            'category' => 'Entertainment',
            'owner' => 'Streaming operations',
            'description' => 'Streaming subscription purchase gate regression fixture.',
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

        $snapshot = app(StreamingPurchaseAdmissionPolicy::class)->snapshot();

        $this->assertFalse($snapshot['available']);
        $this->assertFalse($snapshot['addon_lifecycle']['purchase_operation_registered']);
        $this->assertTrue($snapshot['addon_lifecycle']['catalogue_operation_registered']);
        $this->assertStringContainsString('transaction lookup', $snapshot['gate_reason']);
    }
}
