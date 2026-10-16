<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminVtuOrderOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::query()->create(['name' => 'VTU Operations User', 'email' => $email, 'password' => 'test-password']);
    }

    public function test_vtu_order_queue_requires_platform_operations_permission(): void
    {
        $customer = $this->user('vtu-queue-customer@example.test');
        $refundAdmin = $this->user('vtu-queue-refund-admin@example.test');
        UserPermission::query()->create(['user_id' => $refundAdmin->id, 'permission' => 'refunds.approve']);

        $this->actingAs($customer)->get('/admin/vtu-orders')->assertForbidden();
        $this->actingAs($refundAdmin)->get('/admin/vtu-orders')->assertForbidden();
    }

    public function test_authorized_platform_admin_can_search_and_filter_read_only_order_queue(): void
    {
        $admin = $this->user('vtu-queue-manager@example.test');
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $customer = $this->user('vtu-queue-owner@example.test');
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        $product = VtuProduct::query()->create([
            'service_type' => 'airtime', 'network' => 'mtn', 'code' => 'queue-product',
            'name' => 'Queue test airtime', 'price_minor' => 1000, 'face_value_minor' => 1000,
            'currency' => 'NGN', 'is_active' => true,
        ]);
        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $customer->id, 'wallet_id' => $wallet->id,
            'vtu_product_id' => $product->id, 'idempotency_key' => 'queue-order-1',
            'request_hash' => hash('sha256', 'queue-order-1'), 'service_type' => 'airtime',
            'network' => 'mtn', 'product_code' => $product->code, 'recipient' => '08012345678',
            'amount_minor' => 1000, 'currency' => 'NGN', 'status' => 'awaiting_reconciliation',
            'provider' => 'vtpass', 'provider_reference' => 'QUEUE-PROVIDER-REF',
            'failure_code' => 'provider_pending',
        ]);

        $this->actingAs($admin)
            ->get('/admin/vtu-orders?search=QUEUE-PROVIDER-REF&status=awaiting_reconciliation')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Admin/VtuOrders/Index')
                ->where('filters.search', 'QUEUE-PROVIDER-REF')
                ->where('filters.status', 'awaiting_reconciliation')
                ->where('pagination.total', 1)
                ->where('orders.0.id', $order->public_id)
                ->where('orders.0.customer_email', $customer->email)
                ->where('orders.0.failure_code', 'provider_pending')
                ->missing('orders.0.safe_provider_response'));
    }
}
