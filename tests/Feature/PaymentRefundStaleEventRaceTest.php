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

class PaymentRefundStaleEventRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_refund_stale_event_secret']);
    }

    public function test_stale_failure_event_cannot_reverse_a_settled_successful_refund(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 764321, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create([
            'name' => 'Refund Race Customer',
            'email' => 'refund-race-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Race Approver',
            'email' => 'refund-race-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create([
            'user_id' => $approver->id,
            'permission' => 'refunds.approve',
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 8000,
            idempotencyKey: 'refund-race-seed-'.$customer->id,
            description: 'Seed refund race test wallet',
        );
        $intent = app(PaymentIntentService::class)->create($wallet, 8000, 'paystack');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => 'txn-refund-race-'.$customer->id,
            'paid_at' => now(),
        ])->save();
        $intent = $intent->fresh();

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 3000,
            'reason' => 'Refund event ordering test',
            'idempotency_key' => 'refund-event-race-'.$customer->id,
        ])->assertCreated();
        $refundId = $request->json('data.id');

        $this->actingAs($approver)
            ->postJson('/admin/payment-refunds/'.$refundId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'provider_pending');

        $sendEvent = function (string $eventType) use ($intent): void {
            $payload = [
                'event' => $eventType,
                'data' => [
                    'status' => $eventType === 'refund.processed' ? 'processed' : 'failed',
                    'transaction_reference' => $intent->reference,
                    'refund_reference' => '764321',
                    'amount' => 3000,
                    'currency' => 'NGN',
                ],
            ];
            $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha512', $raw, 'sk_test_refund_stale_event_secret');

            $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ], $raw)->assertOk();
        };

        // The provider first confirms settlement, then a delayed stale failure
        // notification arrives. Terminal state and idempotent ledger compensation
        // must prevent the delayed event from restoring the reservation.
        $sendEvent('refund.processed');
        $sendEvent('refund.failed');

        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $refundId,
            'status' => 'succeeded',
        ]);
        $this->assertSame(5000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }
}
