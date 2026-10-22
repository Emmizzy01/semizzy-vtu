<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuOrder;
use App\Models\VtuProviderAttempt;
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

    public function test_guest_cannot_access_customer_and_admin_workspaces_or_account_services(): void
    {
        foreach ([
            '/dashboard',
            '/customer/dashboard',
            '/services',
            '/profile',
            '/support',
            '/security/transaction-pin',
            '/wallet/manual-deposits',
            '/admin/dashboard',
        ] as $path) {
            $this->get($path)->assertRedirect('/login');
        }

        $this->assertGuest();
    }

    public function test_each_vtu_service_has_its_own_purchase_page_and_service_type(): void
    {
        $customer = $this->account('service-pages@example.test');

        foreach ([
            '/wallet/vtu/airtime' => 'airtime',
            '/wallet/vtu/data' => 'data',
            '/wallet/vtu/electricity' => 'electricity-bill',
            '/wallet/vtu/tv' => 'tv-subscription',
        ] as $path => $serviceType) {
            $this->actingAs($customer)
                ->get($path)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Wallet/VtuPurchase')
                    ->where('serviceType', $serviceType));
        }
    }

    public function test_authenticated_customer_workspace_pages_render_without_route_only_shortcuts(): void
    {
        $customer = $this->account('workspace-page-smoke@example.test');

        foreach ([
            '/profile',
            '/privacy/requests',
            '/account/tiers',
            '/security/transaction-pin',
            '/security/two-factor',
            '/security/passkeys',
            '/wallet/transfer',
            '/wallet/virtual-accounts',
            '/wallet/transfers/history',
            '/wallet/bank-transfer',
            '/wallet/bank-transfer/history',
            '/wallet/scheduled-purchases',
            '/wallet/service-beneficiaries',
        ] as $path) {
            $this->actingAs($customer)->get($path)->assertOk();
        }
    }

    public function test_regular_customer_sees_customer_dashboard(): void
    {
        $customer = $this->account('customer@example.test');
        $customer->forceFill(['user_type' => 'reseller', 'user_level' => 'level_3'])->save();
        $settings = app(\App\Services\IntegrationSettings::class);
        $settings->set('homepage.cashback_line_one', 'Test cashback headline', null);
        $settings->set('homepage.cashback_line_two', 'for verified customers', null);
        $settings->set('homepage.cashback_subtitle', 'Admin-controlled subtitle', null);
        $settings->set('homepage.gadget_sale_title', 'Test gadget sale', null);
        $settings->set('homepage.gadget_sale_subtitle', 'Admin-controlled gadgets', null);
        $settings->set('homepage.referral_title', 'Test referral headline', null);
        $settings->set('homepage.referral_subtitle', 'Admin-controlled referrals', null);

        $this->actingAs($customer)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->where('isAdminPreview', false)
                ->where('customer.type', 'reseller')
                ->where('customer.level', 'level_3')
                ->where('customer.kycStatus', 'unverified')
                ->where('promotions.cashbackLineOne', 'Test cashback headline')
                ->where('promotions.cashbackLineTwo', 'for verified customers')
                ->where('promotions.cashbackSubtitle', 'Admin-controlled subtitle')
                ->where('promotions.gadgetSaleTitle', 'Test gadget sale')
                ->where('promotions.gadgetSaleSubtitle', 'Admin-controlled gadgets')
                ->where('promotions.referralTitle', 'Test referral headline')
                ->where('promotions.referralSubtitle', 'Admin-controlled referrals')
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

    public function test_customer_order_search_does_not_use_internal_provider_references(): void
    {
        $customer = $this->account('provider-reference-search@example.test');
        $wallet = Wallet::query()->create(['user_id' => $customer->id, 'currency' => 'NGN']);
        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'provider-reference-search-airtime',
            'name' => 'Provider reference search airtime',
            'price_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $customer->id,
            'wallet_id' => $wallet->id,
            'vtu_product_id' => $product->id,
            'idempotency_key' => 'provider-reference-search-order',
            'request_hash' => hash('sha256', 'provider-reference-search-order'),
            'service_type' => 'airtime',
            'network' => 'mtn',
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => 10000,
            'currency' => 'NGN',
            'status' => 'successful',
            'provider' => 'test_provider',
            'provider_reference' => 'INTERNAL-PROVIDER-REFERENCE-ONLY',
        ]);

        $this->actingAs($customer)
            ->get('/dashboard?order_search=INTERNAL-PROVIDER-REFERENCE-ONLY')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->has('orders', 0)
                ->where('orderPagination.total', 0));
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

    public function test_refund_only_operator_can_review_refunds_without_global_dashboard_access(): void
    {
        $operator = $this->account('refund-only-admin@example.test');
        $this->grant($operator, 'refunds.approve');

        $this->actingAs($operator)
            ->get('/admin/dashboard')
            ->assertForbidden();

        $this->actingAs($operator)->get('/admin/refunds')->assertOk();
        $this->actingAs($operator)->get('/admin/payment-refunds')->assertOk();

        $this->actingAs($operator)
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->where('isAdminPreview', false));
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

    public function test_admin_dashboard_reports_provider_attempts_and_top_successful_vtu_spenders(): void
    {
        $admin = $this->account('reporting-admin@example.test');
        $spender = $this->account('top-spender@example.test');
        $this->grant($admin, 'staff.permissions.manage');
        $wallet = Wallet::query()->create(['user_id' => $spender->id, 'currency' => 'NGN']);
        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'reporting-airtime',
            'name' => 'Reporting airtime',
            'price_minor' => 125000,
            'currency' => 'NGN',
            'is_active' => true,
        ]);
        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $spender->id,
            'wallet_id' => $wallet->id,
            'vtu_product_id' => $product->id,
            'idempotency_key' => 'reporting-spend-order',
            'request_hash' => hash('sha256', 'reporting-spend-order'),
            'service_type' => 'airtime',
            'network' => 'mtn',
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => 125000,
            'currency' => 'NGN',
            'status' => 'successful',
            'provider' => 'vtpass',
            'provider_reference' => 'REPORTING-ORDER-1',
        ]);

        foreach ([
            ['attempt_number' => 1, 'outcome' => 'failed', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(9)],
            ['attempt_number' => 2, 'outcome' => 'success', 'started_at' => now()->subMinutes(5), 'finished_at' => now()->subMinutes(4)],
        ] as $attempt) {
            VtuProviderAttempt::query()->create([
                'vtu_order_id' => $order->id,
                'attempt_number' => $attempt['attempt_number'],
                'provider' => 'vtpass',
                'outcome' => $attempt['outcome'],
                'definitive_failure' => $attempt['outcome'] === 'failed',
                'started_at' => $attempt['started_at'],
                'finished_at' => $attempt['finished_at'],
            ]);
        }

        $this->actingAs($admin)
            ->get('/admin/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('prototypeMetrics.apiSuccessRate', 50)
                ->where('prototypeMetrics.highValueUsers', null)
                ->has('topVtuSpenders', 1)
                ->where('topVtuSpenders.0.id', $spender->id)
                ->where('topVtuSpenders.0.spendMinor', 125000)
                ->where('providerIntegrations.0.performance.total', 2)
                ->where('providerIntegrations.0.performance.successful', 1)
                ->where('providerIntegrations.0.performance.failed', 1)
                ->where('providerIntegrations.0.performance.successRate', 50));
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

    public function test_login_fallback_keeps_refund_only_operator_on_customer_dashboard(): void
    {
        $operator = $this->account('login-refund-operator@example.test');
        $this->grant($operator, 'refunds.approve');

        $this->post('/login', [
            'email' => $operator->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard')
                ->where('isAdminPreview', false));
        $this->get('/admin/dashboard')->assertForbidden();
        $this->get('/admin/refunds')->assertOk();
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
