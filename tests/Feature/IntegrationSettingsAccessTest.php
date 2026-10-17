<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\IntegrationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_pages_require_staff_management_permission(): void
    {
        $this->get('/admin/payment-gateway')->assertRedirect('/login');
        $this->get('/admin/api-providers')->assertRedirect('/login');

        $user = User::query()->create([
            'name' => 'Ordinary Customer',
            'email' => 'ordinary@example.test',
            'password' => 'not-a-real-password',
        ]);

        $this->actingAs($user)->get('/admin/payment-gateway')->assertForbidden();
        $this->actingAs($user)->get('/admin/api-providers')->assertForbidden();
    }

    public function test_staff_admin_can_view_safe_gateway_and_provider_status_without_secret_values(): void
    {
        $admin = User::query()->create([
            'name' => 'Integration Admin',
            'email' => 'integration-admin@example.test',
            'password' => 'not-a-real-password',
        ]);

        UserPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => 'staff.permissions.manage',
        ]);

        config([
            'services.paystack.secret_key' => 'sk_test_do_not_return_this_value',
            'services.paystack.callback_url' => 'https://semizzy.example.test/wallet/funding/return',
            'services.vtu.driver' => 'vtpass',
            'services.vtu.name' => 'vtpass',
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'vtpass-test-api-key',
            'services.vtu.vtpass.secret_key' => 'vtpass-test-secret-key',
        ]);

        $this->actingAs($admin)->get('/admin/payment-gateway')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PaymentGateway')
                ->where('gateway.configured', true)
                ->where('gateway.mode', 'Test')
                ->where('gateway.callbackHost', 'semizzy.example.test')
                ->missing('gateway.secret_key')
                ->missing('gateway.api_key'));

        $this->actingAs($admin)->get('/admin/api-providers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ApiProviders')
                ->where('provider.driver', 'vtpass')
                ->where('provider.vtpassSelected', true)
                ->where('provider.vtpassBaseUrl', 'https://sandbox.vtpass.com/api')
                ->where('provider.vtpassApiKeyConfigured', true)
                ->where('provider.vtpassSecretKeyConfigured', true)
                ->missing('provider.api_key')
                ->missing('provider.secret_key'));
    }
    public function test_staff_admin_can_save_encrypted_payment_settings(): void
    {
        $admin = User::query()->create([
            'name' => 'Payment Admin',
            'email' => 'payment-admin@example.test',
            'password' => 'not-a-real-password',
        ]);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/payment-gateway', [
            'reason' => 'Update gateway configuration safely',
            'mode' => 'test',
            'secret_key' => 'sk_test_example123',
            'callback_url' => 'https://semizzy.example.test/wallet/funding/return',
            'confirm_live' => false,
        ])->assertSessionHasNoErrors();

        $stored = IntegrationSetting::query()->where('key', 'paystack.secret_key')->firstOrFail();
        $this->assertNotSame('sk_test_example123', $stored->getRawOriginal('value'));
        $this->assertSame('sk_test_example123', $stored->value);
        $this->assertSame($admin->id, $stored->updated_by_user_id);
        $this->assertSame('test', app(\App\Services\IntegrationSettings::class)->get('paystack.mode'));
    }

    public function test_staff_admin_can_save_provider_settings_without_returning_credentials(): void
    {
        $admin = User::query()->create([
            'name' => 'Provider Admin',
            'email' => 'provider-admin@example.test',
            'password' => 'not-a-real-password',
        ]);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/api-providers', [
            'reason' => 'Update provider configuration safely',
            'driver' => 'vtpass',
            'name' => 'VTpass',
            'generic_endpoint' => '',
            'generic_token' => '',
            'vtpass_base_url' => 'https://sandbox.vtpass.com/api',
            'vtpass_api_key' => 'sandbox-api-example',
            'vtpass_secret_key' => 'sandbox-secret-example',
            'confirm_live' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame('vtpass', app(\App\Services\IntegrationSettings::class)->get('vtu.driver'));
        $this->assertSame('sandbox-api-example', app(\App\Services\IntegrationSettings::class)->get('vtu.vtpass.api_key'));
        $this->assertNotSame(
            'sandbox-api-example',
            IntegrationSetting::query()->where('key', 'vtu.vtpass.api_key')->firstOrFail()->getRawOriginal('value')
        );
    }

    public function test_paystack_rejects_malformed_secret_keys(): void
    {
        $admin = User::query()->create([
            'name' => 'Invalid Key Admin',
            'email' => 'invalid-key-admin@example.test',
            'password' => 'not-a-real-password',
        ]);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/payment-gateway', [
            'reason' => 'Update gateway configuration safely',
            'mode' => 'test',
            'secret_key' => 'not-a-paystack-key',
            'callback_url' => 'https://semizzy.example.test/wallet/funding/return',
            'confirm_live' => false,
        ])->assertSessionHasErrors('secret_key');

        $this->assertDatabaseMissing('integration_settings', ['key' => 'paystack.secret_key']);
    }

    public function test_live_paystack_key_requires_confirmation_and_mode_is_detected_from_saved_key(): void
    {
        $admin = User::query()->create([
            'name' => 'Live Key Admin',
            'email' => 'live-key-admin@example.test',
            'password' => 'not-a-real-password',
        ]);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/payment-gateway', [
            'reason' => 'Update gateway configuration safely',
            'mode' => 'live',
            'secret_key' => 'sk_live_semizzy_live_test_key',
            'callback_url' => 'https://semizzy.example.test/wallet/funding/return',
            'confirm_live' => false,
        ])->assertSessionHasErrors('confirm_live');

        $this->assertDatabaseMissing('integration_settings', ['key' => 'paystack.secret_key']);

        $this->actingAs($admin)->put('/admin/payment-gateway', [
            'reason' => 'Update gateway configuration safely',
            'mode' => 'live',
            'secret_key' => 'sk_live_semizzy_live_test_key',
            'callback_url' => 'https://semizzy.example.test/wallet/funding/return',
            'confirm_live' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($admin)->get('/admin/payment-gateway')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/PaymentGateway')
                ->where('gateway.mode', 'Live'));
    }


    public function test_live_vtpass_mode_requires_both_credentials_before_saving(): void
    {
        $admin = User::query()->create([
            'name' => 'Live VTpass Admin',
            'email' => 'live-vtpass-admin@example.test',
            'password' => 'not-a-real-password',
        ]);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        config([
            'services.vtu.vtpass.api_key' => '',
            'services.vtu.vtpass.secret_key' => '',
        ]);

        $this->actingAs($admin)->put('/admin/api-providers', [
            'reason' => 'Attempt live mode without credentials',
            'driver' => 'vtpass',
            'name' => 'VTpass',
            'generic_endpoint' => '',
            'generic_token' => '',
            'vtpass_base_url' => 'https://vtpass.com/api',
            'vtpass_api_key' => '',
            'vtpass_secret_key' => '',
            'confirm_live' => true,
        ])->assertSessionHasErrors('vtpass_api_key');

        $this->assertDatabaseMissing('integration_settings', ['key' => 'vtu.vtpass.base_url']);
    }

}
