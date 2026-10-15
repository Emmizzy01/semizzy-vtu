<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Str;
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
                ->where('isAdminPreview', false)
                ->has('transactions')
                ->has('transactionPagination')
                ->where('transactionFilters.status', 'all')
                ->has('orders')
                ->has('orderPagination')
                ->has('fundingHistory'));
    }

    public function test_customer_dashboard_only_returns_transactions_from_their_own_wallet(): void
    {
        $customer = $this->account('activity-owner@example.test');
        $otherCustomer = $this->account('other-activity-owner@example.test');
        $customerWallet = Wallet::query()->create(['user_id' => $customer->id, 'currency' => 'NGN']);
        $otherWallet = Wallet::query()->create(['user_id' => $otherCustomer->id, 'currency' => 'NGN']);

        foreach ([
            [$customerWallet, 'OWN-REFERENCE', 'Own wallet transaction'],
            [$otherWallet, 'OTHER-REFERENCE', 'Another customer transaction'],
        ] as [$wallet, $reference, $description]) {
            WalletTransaction::query()->create([
                'public_id' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'idempotency_key' => 'test-' . $reference,
                'request_hash' => hash('sha256', $reference),
                'type' => 'credit',
                'status' => 'succeeded',
                'currency' => 'NGN',
                'amount_minor' => 10000,
                'balance_before_minor' => 0,
                'balance_after_minor' => 10000,
                'reference' => $reference,
                'description' => $description,
            ]);
        }

        $this->actingAs($customer)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->has('transactions', 1)
                ->where('transactions.0.reference', 'OWN-REFERENCE')
                ->where('transactions.0.description', 'Own wallet transaction'));
    }

    public function test_customer_transaction_history_is_paginated_and_searchable_on_the_server(): void
    {
        $customer = $this->account('history-customer@example.test');
        $wallet = Wallet::query()->create(['user_id' => $customer->id, 'currency' => 'NGN']);

        for ($index = 1; $index <= 12; $index++) {
            $reference = sprintf('ACTIVITY-%02d', $index);
            WalletTransaction::query()->create([
                'public_id' => (string) Str::uuid(),
                'wallet_id' => $wallet->id,
                'idempotency_key' => 'history-' . $index,
                'request_hash' => hash('sha256', $reference),
                'type' => 'credit',
                'status' => 'succeeded',
                'currency' => 'NGN',
                'amount_minor' => 10000,
                'balance_before_minor' => ($index - 1) * 10000,
                'balance_after_minor' => $index * 10000,
                'reference' => $reference,
                'description' => 'Customer activity history',
            ]);
        }

        $this->actingAs($customer)
            ->get('/dashboard?transactions_page=2&transaction_search=ACTIVITY&transaction_status=successful')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->has('transactions', 2)
                ->where('transactionPagination.currentPage', 2)
                ->where('transactionPagination.lastPage', 2)
                ->where('transactionPagination.total', 12)
                ->where('transactionFilters.search', 'ACTIVITY')
                ->where('transactionFilters.status', 'successful')
                ->where('transactions.0.reference', 'ACTIVITY-02'));
    }

    public function test_customer_order_history_is_paginated_and_searchable_without_exposing_other_users_orders(): void
    {
        $customer = $this->account('order-history-customer@example.test');
        $otherCustomer = $this->account('other-order-history-customer@example.test');
        $wallet = Wallet::query()->create(['user_id' => $customer->id, 'currency' => 'NGN']);
        $otherWallet = Wallet::query()->create(['user_id' => $otherCustomer->id, 'currency' => 'NGN']);
        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'history-airtime',
            'name' => 'History airtime',
            'price_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        for ($index = 1; $index <= 12; $index++) {
            VtuOrder::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $customer->id,
                'wallet_id' => $wallet->id,
                'vtu_product_id' => $product->id,
                'idempotency_key' => 'order-history-' . $index,
                'request_hash' => hash('sha256', 'order-history-' . $index),
                'service_type' => 'airtime',
                'network' => 'mtn',
                'product_code' => $product->code,
                'recipient' => '08012345678',
                'amount_minor' => 10000,
                'currency' => 'NGN',
                'status' => 'successful',
                'provider' => 'test_provider',
                'provider_reference' => 'ORDER-HISTORY-' . $index,
            ]);
        }

        VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $otherCustomer->id,
            'wallet_id' => $otherWallet->id,
            'vtu_product_id' => $product->id,
            'idempotency_key' => 'other-customer-order',
            'request_hash' => hash('sha256', 'other-customer-order'),
            'service_type' => 'airtime',
            'network' => 'mtn',
            'product_code' => $product->code,
            'recipient' => '08099999999',
            'amount_minor' => 10000,
            'currency' => 'NGN',
            'status' => 'successful',
            'provider' => 'test_provider',
            'provider_reference' => 'PRIVATE-ORDER-REFERENCE',
        ]);

        $this->actingAs($customer)
            ->get('/dashboard?orders_page=2&order_search=08012345678&order_status=successful')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->has('orders', 2)
                ->where('orderPagination.currentPage', 2)
                ->where('orderPagination.lastPage', 2)
                ->where('orderPagination.total', 12)
                ->where('orderFilters.search', '08012345678')
                ->where('orderFilters.status', 'successful')
                ->where('orders.0.recipient', '08012345678'));
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
                ->where('capabilities.approveRefunds', false)
                ->where('metrics.users', 1)
                ->where('metrics.refundsNeedingAction', null));
    }

    public function test_refund_only_admin_receives_only_the_metrics_allowed_by_their_capability(): void
    {
        $admin = $this->account('refund-only-admin@example.test');
        $this->grant($admin, 'refunds.approve');

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/Dashboard')
                ->where('capabilities.manageStaff', false)
                ->where('capabilities.approveRefunds', true)
                ->where('metrics.users', null)
                ->where('metrics.refundsNeedingAction', 0)
                ->where('metrics.wallets', 0)
                ->where('metrics.pendingFunding', 0)
                ->where('metrics.unresolvedVtuOrders', 0));
    }

    public function test_admin_can_preview_the_customer_dashboard_for_their_own_account(): void
    {
        $admin = $this->account('preview-admin@example.test');
        $this->grant($admin, 'staff.permissions.manage');

        $this->actingAs($admin)
            ->get('/customer/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->where('isAdminPreview', true));
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
