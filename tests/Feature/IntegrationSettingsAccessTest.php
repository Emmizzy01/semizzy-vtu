<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
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
}
