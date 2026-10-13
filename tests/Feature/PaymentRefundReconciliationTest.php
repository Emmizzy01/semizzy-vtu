<?php

namespace Tests\Feature;

use App\Models\PaymentRefund;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Payments\PaymentRefundReconciliationService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentRefundReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_refund_is_reconciled_from_paystack_canonical_record(): void
    {
        Http::fake([
            'api.paystack.co/refund/123456' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 123456,
                    'transaction' => 987654,
                    'amount' => 2500,
                    'currency' => 'NGN',
                    'status' => 'processed',
                ],
            ], 200),
        ]);
        config(['services.paystack.secret_key' => 'sk_test_refund_reconcile']);

        $user = User::query()->create([
            'name' => 'Refund Reconcile Customer',
            'email' => 'refund-reconcile@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => '987654',
            'paid_at' => now(),
        ])->save();

        $refund = PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'refund-reconcile-processed',
            'amount_minor' => 2500,
            'reason' => 'Reconciliation test',
            'status' => 'provider_pending',
            'provider_refund_reference' => '123456',
            'provider_status' => 'pending',
            'metadata' => ['currency' => 'NGN'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
        ]);

        $stats = app(PaymentRefundReconciliationService::class)->reconcile();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['reconciled']);
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame('processed', $refund->fresh()->provider_status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund/123456'));
    }

    public function test_provider_amount_mismatch_never_marks_refund_succeeded(): void
    {
        Http::fake([
            'api.paystack.co/refund/123457' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 123457,
                    'transaction' => 987655,
                    'amount' => 9999,
                    'currency' => 'NGN',
                    'status' => 'processed',
                ],
            ], 200),
        ]);
        config(['services.paystack.secret_key' => 'sk_test_refund_reconcile']);

        $user = User::query()->create([
            'name' => 'Refund Mismatch Customer',
            'email' => 'refund-mismatch-reconcile@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill(['status' => 'paid', 'provider_transaction_id' => '987655', 'paid_at' => now()])->save();

        $refund = PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'refund-reconcile-mismatch',
            'amount_minor' => 2500,
            'reason' => 'Mismatch test',
            'status' => 'provider_pending',
            'provider_refund_reference' => '123457',
            'metadata' => ['currency' => 'NGN'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
        ]);

        $stats = app(PaymentRefundReconciliationService::class)->reconcile();

        $this->assertSame(1, $stats['mismatched']);
        $this->assertSame('review_required', $refund->fresh()->status);
        $this->assertNotSame('succeeded', $refund->fresh()->status);
    }
}
