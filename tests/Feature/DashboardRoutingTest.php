<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email, string $password = 'CorrectPassword1234'): User
    {
        return User::query()->create([
            'name' => 'Test Account',
            'email' => $email,
            'password' => $password,
        ]);
    }

    private function grant(User $user, string $permission): void
    {
        UserPermission::query()->create([
            'user_id' => $user->id,
            'permission' => $permission,
        ]);
    }

    public function test_regular_customer_sees_customer_dashboard(): void
    {
        $customer = $this->account('customer@example.test');

        $this->actingAs($customer)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->has('transactions')
                ->has('orders')
                ->has('fundingHistory'));
    }

    public function test_user_with_admin_permission_is_routed_to_admin_command_center(): void
    {
        $admin = $this->account('admin@example.test');
        $this->grant($admin, 'staff.permissions.manage');

        $this->actingAs($admin)
            ->get('/dashboard')
            ->assertRedirect('/admin/dashboard');

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('capabilities.manageStaff', true)
                ->where('capabilities.approveRefunds', false));
    }

    public function test_admin_can_preview_the_customer_dashboard_for_their_own_account(): void
    {
        $admin = $this->account('preview-admin@example.test');
        $this->grant($admin, 'staff.permissions.manage');

        $this->actingAs($admin)
            ->get('/customer/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_admin_dashboard_rejects_accounts_without_admin_permission(): void
    {
        $customer = $this->account('ordinary@example.test');

        $this->actingAs($customer)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_login_normalizes_email_case_and_surrounding_whitespace(): void
    {
        $customer = $this->account('case-normalized@example.test');

        $this->post('/login', [
            'email' => '  CASE-NORMALIZED@EXAMPLE.TEST  ',
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($customer);
    }

    public function test_login_fallback_routes_customer_to_dashboard(): void
    {
        $customer = $this->account('login-customer@example.test');

        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_login_fallback_routes_permissioned_staff_to_admin_dashboard(): void
    {
        $admin = $this->account('login-admin@example.test');
        $this->grant($admin, 'refunds.approve');

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')->assertRedirect('/admin/dashboard');
        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('capabilities.approveRefunds', true));
    }

    public function test_registration_redirects_new_customer_to_dashboard(): void
    {
        $this->post('/register', [
            'name' => 'New Customer',
            'email' => 'new-customer@example.test',
            'password' => 'ValidPassword1234',
            'password_confirmation' => 'ValidPassword1234',
        ])->assertRedirect('/dashboard');

        $this->assertDatabaseHas('users', ['email' => 'new-customer@example.test']);
        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard'));
    }

    public function test_intended_redirect_is_preserved_after_login(): void
    {
        $customer = $this->account('intended@example.test');

        $this->get('/wallet/vtu')->assertRedirect('/login');

        $this->post('/login', [
            'email' => $customer->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/wallet/vtu');
    }
}
