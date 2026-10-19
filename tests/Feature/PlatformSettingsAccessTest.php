<?php

namespace Tests\Feature;

use App\Models\PlatformSettingAudit;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_are_permission_gated_audited_and_enforced_for_vtu(): void
    {
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'site-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($customer)->get('/admin/platform-settings')->assertForbidden();

        $admin = User::query()->create(['name' => 'Platform Admin', 'email' => 'site-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->get('/admin/platform-settings')->assertOk();
        app(IntegrationSettings::class)->set('manual_deposit.bank_name', 'Test Bank', $admin->id);
        app(IntegrationSettings::class)->set('manual_deposit.account_name', 'Semizzy Test Account', $admin->id);
        app(IntegrationSettings::class)->set('manual_deposit.account_number', '0123456789', $admin->id);
        $this->actingAs($admin)->put('/admin/platform-settings', [
            'site_name' => 'Semizzy Test', 'support_email' => 'support@example.test',
            'support_phone' => '+2348000000000', 'theme_skin' => 'minimal-blue', 'maintenance_mode' => false,
            'airtime_data_enabled' => false, 'manual_deposit_enabled' => true,
            'homepage_browser_title' => 'Semizzy VTU - Single File Dashboard',
            'homepage_cashback_line_one' => 'Enjoy 10% cashback',
            'homepage_cashback_line_two' => 'on data purchases',
            'homepage_cashback_subtitle' => 'Limited time offer',
            'homepage_gadget_sale_title' => 'Big Gadget Sale',
            'homepage_gadget_sale_subtitle' => 'Phones and laptops',
            'homepage_referral_title' => 'Refer and Earn',
            'homepage_referral_subtitle' => 'Invite friends to Semizzy',
            'reason' => 'Temporarily pause airtime orders',
        ])->assertRedirect();

        $this->assertSame('0', (string) app(IntegrationSettings::class)->get('service.airtime_data.enabled'));
        $this->assertSame('minimal-blue', (string) app(IntegrationSettings::class)->get('site.theme_skin'));
        $this->assertGreaterThanOrEqual(1, PlatformSettingAudit::query()->count());
        $this->assertDatabaseHas('platform_setting_audits', ['setting_key' => 'service.airtime_data.enabled', 'actor_user_id' => $admin->id]);
        $this->actingAs($customer)->getJson('/wallet/vtu/products')->assertStatus(503);
    }

    public function test_global_site_control_cannot_enable_manual_deposits_without_bank_instructions(): void
    {
        $admin = User::query()->create(['name' => 'Safe Site Admin', 'email' => 'safe-site-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/platform-settings', [
            'site_name' => 'Semizzy', 'support_email' => 'support@example.test',
            'support_phone' => '+2348000000000', 'theme_skin' => 'modern-green',
            'maintenance_mode' => false, 'airtime_data_enabled' => true,
            'manual_deposit_enabled' => true,
            'homepage_browser_title' => 'Semizzy VTU - Single File Dashboard',
            'homepage_cashback_line_one' => 'Enjoy 10% cashback',
            'homepage_cashback_line_two' => 'on data purchases',
            'homepage_cashback_subtitle' => 'Limited time offer',
            'homepage_gadget_sale_title' => 'Big Gadget Sale',
            'homepage_gadget_sale_subtitle' => 'Phones and laptops',
            'homepage_referral_title' => 'Refer and Earn',
            'homepage_referral_subtitle' => 'Invite friends to Semizzy',
            'reason' => 'Enable manual bank deposit',
        ])->assertSessionHasErrors('manual_deposit_enabled');

        $this->assertSame('0', (string) app(IntegrationSettings::class)->get('manual_deposit.enabled', '0'));
    }

    public function test_maintenance_mode_blocks_customers_but_allows_platform_admins(): void
    {
        $customer = User::query()->create(['name' => 'Maintenance Customer', 'email' => 'maintenance-customer@example.test', 'password' => 'not-a-real-password']);
        $admin = User::query()->create(['name' => 'Maintenance Admin', 'email' => 'maintenance-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        app(IntegrationSettings::class)->set('site.maintenance_mode', '1', $admin->id);

        $this->actingAs($customer)->get('/dashboard')->assertStatus(503)->assertInertia(fn ($page) => $page->component('Maintenance'));
        $this->actingAs($admin)->get('/admin/platform-settings')->assertOk();
    }
}
