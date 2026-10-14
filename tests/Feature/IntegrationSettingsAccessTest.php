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
            'secret_key' => 'sk_test_example123',
            'callback_url' => 'https://semizzy.example.test/wallet/funding/return',
            'confirm_live' => false,
        ])->assertSessionHasNoErrors();

        $stored = IntegrationSetting::query()->where('key', 'paystack.secret_key')->firstOrFail();
        $this->assertNotSame('sk_test_example123', $stored->getRawOriginal('value'));
        $this->assertSame('sk_test_example123', $stored->value);
        $this->assertSame($admin->id, $stored->updated_by_user_id);
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

}
