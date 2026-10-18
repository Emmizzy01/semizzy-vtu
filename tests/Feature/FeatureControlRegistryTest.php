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

    public function test_feature_cannot_be_enabled_until_its_dependency_is_enabled(): void
    {
        $admin = User::query()->create(['name' => 'Dependency Admin', 'email' => 'feature-dependency@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        PlatformFeatureControl::query()->create([
            'feature_key' => 'test.dependency', 'label' => 'Dependency', 'category' => 'test', 'owner' => 'test-suite', 'description' => 'Temporary dependency test fixture',
            'dependencies' => [], 'default_enabled' => false, 'is_enabled' => false,
            'is_core' => false, 'is_implemented' => true,
        ]);
        PlatformFeatureControl::query()->create([
            'feature_key' => 'test.dependent', 'label' => 'Dependent', 'category' => 'test', 'owner' => 'test-suite', 'description' => 'Temporary dependent test fixture',
            'dependencies' => ['test.dependency'], 'default_enabled' => false, 'is_enabled' => false,
            'is_core' => false, 'is_implemented' => true,
        ]);

        $this->actingAs($admin)->put('/admin/feature-controls/test.dependent', [
            'is_enabled' => true, 'reason' => 'Enable test dependent feature',
        ])->assertStatus(422);

        $this->assertDatabaseHas('platform_feature_controls', ['feature_key' => 'test.dependent', 'is_enabled' => 0]);
    }

    public function test_enabled_dependent_prevents_disabling_its_dependency(): void
    {
        $admin = User::query()->create(['name' => 'Dependency Admin', 'email' => 'feature-dependent@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        PlatformFeatureControl::query()->create([
            'feature_key' => 'test.dependency', 'label' => 'Dependency', 'category' => 'test', 'owner' => 'test-suite', 'description' => 'Temporary dependency test fixture',
            'dependencies' => [], 'default_enabled' => true, 'is_enabled' => true,
            'is_core' => false, 'is_implemented' => true,
        ]);
        PlatformFeatureControl::query()->create([
            'feature_key' => 'test.dependent', 'label' => 'Dependent', 'category' => 'test', 'owner' => 'test-suite', 'description' => 'Temporary dependent test fixture',
            'dependencies' => ['test.dependency'], 'default_enabled' => true, 'is_enabled' => true,
            'is_core' => false, 'is_implemented' => true,
        ]);

        $this->actingAs($admin)->put('/admin/feature-controls/test.dependency', [
            'is_enabled' => false, 'reason' => 'Disable test dependency',
        ])->assertStatus(422);

        $this->assertDatabaseHas('platform_feature_controls', ['feature_key' => 'test.dependency', 'is_enabled' => 1]);
        $this->assertDatabaseHas('platform_feature_controls', ['feature_key' => 'test.dependent', 'is_enabled' => 1]);
    }

    public function test_manual_deposits_cannot_be_enabled_without_bank_transfer_details(): void
    {
        $admin = User::query()->create(['name' => 'Feature Admin', 'email' => 'manual-feature-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/feature-controls/service.manual_deposit', [
            'is_enabled' => true,
            'reason' => 'Enable manual deposits after configuration',
        ])->assertSessionHasErrors('is_enabled');

        $this->assertDatabaseHas('platform_feature_controls', [
            'feature_key' => 'service.manual_deposit',
            'is_enabled' => 0,
        ]);
    }

    public function test_removed_website_builder_and_domains_feature_is_not_offered_in_platform_controls(): void
    {
        $this->assertDatabaseMissing('platform_feature_controls', ['feature_key' => 'service.websites']);
    }

    public function test_requested_services_are_registered_but_stay_disabled_until_workflows_are_verified(): void
    {
        foreach ([
            'service.streaming_subscriptions',
            'service.betting',
            'service.social_accounts',
            'service.number_verification',
            'service.vtu_website_requests',
        ] as $featureKey) {
            $this->assertDatabaseHas('platform_feature_controls', [
                'feature_key' => $featureKey,
                'is_enabled' => 0,
                'is_implemented' => 0,
            ]);
        }
    }
}
