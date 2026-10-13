<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Vtu\VtuOrderService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.vtu.name' => 'test_provider',
            'services.vtu.endpoint' => 'https://provider.example.test/api',
            'services.vtu.token' => 'test-secret-token',
        ]);
    }

    public function test_successful_purchase_is_persisted_and_debited_once(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'success',
            'provider_reference' => 'provider-order-001',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);

        $order = app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'purchase-success-1');

        $this->assertSame('successful', $order->status);
        $this->assertSame('provider-order-001', $order->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        Http::assertSentCount(1);
    }

    public function test_duplicate_successful_purchase_is_not_submitted_or_debited_twice(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            return Http::response([
                'status' => 'success',
                'provider_reference' => 'provider-order-duplicate-success',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $first = $service->purchase($user, $product->code, '08012345678', 'duplicate-success-key');
        $duplicate = $service->purchase($user, $product->code, '08012345678', 'duplicate-success-key');

        $this->assertSame('successful', $first->status);
        $this->assertSame($first->id, $duplicate->id);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, $attempts, 'A duplicate successful purchase must not be resubmitted.');
    }

    public function test_definitive_provider_failure_refunds_through_the_ledger(): void
    {
        Http::fake(fn () => Http::response([
            'status' => 'failed',
            'message_code' => 'INVALID_RECIPIENT',
            'provider_reference' => 'provider-order-002',
        ], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);

        $order = app(VtuOrderService::class)->purchase($user, $product->code, '08012345678', 'purchase-failed-1');

        $this->assertSame('failed', $order->status);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 3);
        Http::assertSentCount(1);
    }

    public function test_timeout_marks_order_for_reconciliation_and_retry_does_not_resubmit(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('Simulated provider timeout');
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $first = $service->purchase($user, $product->code, '08012345678', 'purchase-timeout-1');
        $retry = $service->purchase($user, $product->code, '08012345678', 'purchase-timeout-1');

        $this->assertSame('awaiting_reconciliation', $first->status);
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(1, $attempts, 'A duplicate request must not resubmit the timed-out purchase.');
    }

    public function test_pending_provider_order_can_be_reconciled_without_resubmitting(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST') {
                return Http::response(['status' => 'pending', 'provider_reference' => 'provider-order-003'], 200);
            }

            return Http::response(['status' => 'success', 'provider_reference' => 'provider-order-003'], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $order = $service->purchase($user, $product->code, '08012345678', 'purchase-reconcile-1');
        $this->assertSame('processing', $order->status);

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('successful', $reconciled->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        Http::assertSentCount(2);
    }

    public function test_reusing_idempotency_key_for_different_purchase_is_rejected(): void
    {
        Http::fake(fn () => Http::response(['status' => 'pending'], 200));

        [$user, , $product] = $this->preparedCustomer(1000, 5000);
        $service = app(VtuOrderService::class);

        $service->purchase($user, $product->code, '08012345678', 'same-purchase-key');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
        $service->purchase($user, $product->code, '08087654321', 'same-purchase-key');
    }

    public function test_purchase_uses_server_catalogue_price_not_a_client_supplied_amount(): void
    {
        Http::fake(fn () => Http::response(['status' => 'success'], 200));

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);

        $response = $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'idempotency_key' => 'client-price-tamper-1',
            'amount_minor' => 1,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.amount_minor', 1000);

        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
    }

    public function test_customer_cannot_view_another_customers_vtu_order(): void
    {
        Http::fake(fn () => Http::response(['status' => 'pending'], 200));

        [$owner, , $product] = $this->preparedCustomer(1000, 1500);
        $order = app(VtuOrderService::class)->purchase(
            $owner,
            $product->code,
            '08012345678',
            'private-order-check-1',
        );

        $otherCustomer = User::query()->create([
            'name' => 'Different Customer',
            'email' => 'different-customer-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);

        $this->actingAs($otherCustomer)
            ->getJson('/wallet/vtu/orders/'.$order->public_id)
            ->assertNotFound();
    }

    /** @return array{User, \App\Models\Wallet, VtuProduct} */
    public function test_reconciliation_keeps_ambiguous_order_flag_when_provider_still_reports_pending(): void
    {
        $requests = 0;
        Http::fake(function ($request) use (&$requests) {
            $requests++;
            if ($request->method() === 'POST') {
                throw new ConnectionException('Simulated submission timeout');
            }

            return Http::response([
                'status' => 'pending',
                'provider_reference' => 'provider-order-still-pending',
            ], 200);
        });

        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $service = app(VtuOrderService::class);

        $order = $service->purchase($user, $product->code, '08012345678', 'pending-after-timeout-1');
        $this->assertSame('awaiting_reconciliation', $order->status);

        $reconciled = $service->reconcile($order->fresh());

        $this->assertSame('awaiting_reconciliation', $reconciled->status);
        $this->assertSame('provider-order-still-pending', $reconciled->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertSame(2, $requests, 'The submission attempt and reconciliation lookup should both occur.');
    }

    public function test_insufficient_funds_does_not_submit_or_create_vtu_order(): void
    {
        Http::fake();

        [$user, $wallet, $product] = $this->preparedCustomer(1500, 1000);

        try {
            app(VtuOrderService::class)->purchase(
                $user,
                $product->code,
                '08012345678',
                'insufficient-funds-1',
            );
            $this->fail('The purchase should be rejected when the wallet balance is insufficient.');
        } catch (\App\Exceptions\Wallet\InsufficientWalletFunds) {
            $this->assertSame(1000, (int) $wallet->fresh()->available_balance_minor);
            $this->assertDatabaseCount('vtu_orders', 0);
            $this->assertDatabaseCount('wallet_transactions', 1);
            Http::assertNothingSent();
        }
    }

    /** @return array{User, \App\Models\Wallet, VtuProduct} */
    private function preparedCustomer(int $productPriceMinor, int $initialBalanceMinor): array
    {
        $user = User::query()->create([
            'name' => 'VTU Test Customer',
            'email' => 'vtu-customer-'.uniqid().'@example.test',
            'password' => 'test-password',
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: $initialBalanceMinor,
            idempotencyKey: 'test-funding:'.$user->getKey(),
            reference: 'test-funding:'.$user->getKey(),
            description: 'Test-only wallet funding',
        );

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'test_network',
            'code' => 'test-airtime-'.uniqid(),
            'name' => 'Test Airtime',
            'price_minor' => $productPriceMinor,
            'face_value_minor' => $productPriceMinor,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        return [$user, $wallet->fresh(), $product];
    }
}
