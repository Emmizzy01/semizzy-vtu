<?php

namespace Tests\Feature;

use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceCommission;
use App\Models\BusinessWorkspaceStorefront;
use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Wallets\WalletAccountService;
use App\Services\Workspaces\StorefrontCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontCommissionSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_settlement_credits_the_beneficiary_once(): void
    {
        $owner = User::query()->create([
            'name' => 'Reseller Owner',
            'email' => 'commission-owner@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $customer = User::query()->create([
            'name' => 'Customer',
            'email' => 'commission-customer@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'commission-admin@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $ownerWallet = app(WalletAccountService::class)->openForUser($owner, 'NGN');
        $customerWallet = app(WalletAccountService::class)->openForUser($customer, 'NGN');

        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Commission Test Workspace',
            'slug' => 'commission-test-workspace',
            'type' => 'reseller',
            'status' => 'active',
        ]);
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id,
            'slug' => 'commission-test-store',
            'display_name' => 'Commission Test Store',
            'status' => 'published',
            'enabled_services' => ['data'],
            'accent_color' => '#087443',
            'commission_rate_basis_points' => 1000,
        ]);
        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'commission-test-data',
            'name' => 'Test Data Plan',
            'price_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => [],
        ]);
        $order = VtuOrder::query()->create([
            'public_id' => 'c8c0d0f0-2b5e-4b45-8b80-1fbb7b9b0021',
            'user_id' => $customer->id,
            'wallet_id' => $customerWallet->id,
            'vtu_product_id' => $product->id,
            'business_workspace_storefront_id' => $storefront->id,
            'wallet_transaction_id' => null,
            'idempotency_key' => 'commission-order-idempotency',
            'request_hash' => hash('sha256', 'commission-order'),
            'service_type' => 'data',
            'network' => 'mtn',
            'product_code' => $product->code,
            'recipient' => '08030000000',
            'amount_minor' => 10000,
            'currency' => 'NGN',
            'status' => 'successful',
            'provider' => 'test-provider',
            'provider_reference' => 'commission-test-reference',
        ]);
        $commission = BusinessWorkspaceCommission::query()->create([
            'business_workspace_storefront_id' => $storefront->id,
            'vtu_order_id' => $order->id,
            'beneficiary_user_id' => $owner->id,
            'gross_amount_minor' => 10000,
            'rate_basis_points' => 1000,
            'commission_amount_minor' => 1000,
            'status' => 'accrued',
        ]);

        $service = app(StorefrontCommissionService::class);
        $first = $service->settle($commission->id, $admin);
        $second = $service->settle($commission->id, $admin);

        $this->assertSame('settled', $first->status);
        $this->assertSame('settled', $second->status);
        $this->assertSame($first->settled_wallet_transaction_id, $second->settled_wallet_transaction_id);
        $this->assertNotNull($first->settled_at);
        $this->assertSame(1000, (int) $ownerWallet->fresh()->balance_minor);
        $this->assertSame(1000, (int) $ownerWallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseHas('business_workspace_commissions', [
            'id' => $commission->id,
            'status' => 'settled',
            'settled_wallet_transaction_id' => $first->settled_wallet_transaction_id,
        ]);
    }
}
