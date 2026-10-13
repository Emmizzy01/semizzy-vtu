<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\UserPermission;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_refund_workflow_secret']);
    }

    private function paidIntent(User $user, int $amount): PaymentIntent
    {
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', $amount, 'seed-'.$user->id, description: 'Test funding');
        $intent = app(PaymentIntentService::class)->create($wallet, $amount, 'paystack');
        $intent->forceFill(['status' => 'paid', 'provider_transaction_id' => 'txn-'.$user->id, 'paid_at' => now()])->save();
        return $intent->fresh();
    }

    public function test_refund_request_needs_explicit_approval_permission(): void
    {
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-customer@example.test', 'password' => 'test-password']);
        $intent = $this->paidIntent($customer, 10000);

        $response = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Duplicate funding',
            'idempotency_key' => 'refund-request-1',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->actingAs($customer)->postJson('/admin/payment-refunds/'.$response->json('data.id').'/approve')->assertForbidden();
        $this->assertSame(10000, (int) $intent->wallet()->first()->fresh()->balance_minor);
        Http::assertNothingSent();
    }

    public function test_authorized_approval_reserves_funds_and_calls_provider(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 'refund_provider_123', 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-customer2@example.test', 'password' => 'test-password']);
        $approver = User::query()->create(['name' => 'Approver', 'email' => 'refund-approver@example.test', 'password' => 'test-password']);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Partial refund',
            'idempotency_key' => 'refund-request-2',
        ])->assertCreated();

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$request->json('data.id').'/approve')
            ->assertOk()->assertJsonPath('data.status', 'provider_pending');

        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request['amount'] === 2500);
    }

    public function test_insufficient_available_balance_blocks_refund_without_provider_call(): void
    {
        Http::fake();
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-low@example.test', 'password' => 'test-password']);
        $approver = User::query()->create(['name' => 'Approver', 'email' => 'refund-low-approver@example.test', 'password' => 'test-password']);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill(['status' => 'paid', 'provider_transaction_id' => 'txn-low', 'paid_at' => now()])->save();

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 5000,
            'reason' => 'Balance already spent',
            'idempotency_key' => 'refund-request-low',
        ])->assertCreated();

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$request->json('data.id').'/approve')
            ->assertStatus(409)->assertJsonPath('data.status', 'blocked_insufficient_wallet_funds');
        Http::assertNothingSent();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
