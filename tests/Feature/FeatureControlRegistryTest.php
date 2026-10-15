<?php

namespace Tests\Feature;

use App\Models\PlatformFeatureControl;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureControlRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_switches_are_audited_and_sync_live_service_controls(): void
    {
        $admin = User::query()->create(['name' => 'Feature Admin', 'email' => 'feature-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->get('/admin/feature-controls')->assertOk();
        $this->actingAs($admin)->put('/admin/feature-controls/service.airtime_data', ['is_enabled' => false, 'reason' => 'Pause service during provider incident'])->assertRedirect();
        $this->assertDatabaseHas('platform_feature_controls', ['feature_key' => 'service.airtime_data', 'is_enabled' => 0]);
        $this->assertDatabaseHas('platform_setting_audits', ['setting_key' => 'feature.service.airtime_data', 'actor_user_id' => $admin->id]);
        $this->assertSame('0', (string) app(IntegrationSettings::class)->get('service.airtime_data.enabled'));
    }

    public function test_core_and_roadmap_features_cannot_be_activated_or_disabled(): void
    {
        $admin = User::query()->create(['name' => 'Feature Admin', 'email' => 'feature-admin2@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->put('/admin/feature-controls/core.wallet_ledger', ['is_enabled' => false, 'reason' => 'Unsafe attempt to disable core'])->assertStatus(422);
        $this->actingAs($admin)->put('/admin/feature-controls/service.marketplace', ['is_enabled' => true, 'reason' => 'Marketplace not ready to activate'])->assertStatus(422);
        $this->assertDatabaseHas('platform_feature_controls', ['feature_key' => 'core.wallet_ledger', 'is_enabled' => 1]);
        $this->assertDatabaseHas('platform_feature_controls', ['feature_key' => 'service.marketplace', 'is_enabled' => 0]);
    }
}
