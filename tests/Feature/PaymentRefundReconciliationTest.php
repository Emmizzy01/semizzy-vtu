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
        $lookupCount = 0;
        Http::fake(function ($request) use (&$lookupCount) {
            if (! str_ends_with($request->url(), '/refund/123457')) {
                return Http::response(['status' => false, 'message' => 'Unexpected test request'], 404);
            }

            $lookupCount++;

            return Http::response([
                'status' => true,
                'data' => [
                    'id' => 123457,
                    'transaction' => 987655,
                    'amount' => $lookupCount === 1 ? 9999 : 2500,
                    'currency' => 'NGN',
                    'status' => 'processed',
                ],
            ], 200);
        });
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

        // Only a fresh canonical provider lookup with matching details may resolve review.
        $refund->forceFill(['status_changed_at' => now()->subHour()])->save();
        $recovered = app(\App\Services\Payments\PaymentRefundService::class)
            ->reconcileProviderStatus($refund->public_id);

        $this->assertSame('reconciled', $recovered['outcome']);
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame('processed', $refund->fresh()->provider_status);
        Http::assertSentCount(2);
    }

    public function test_mismatch_result_does_not_report_review_required_after_concurrent_terminal_settlement(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_refund_reconcile']);

        $user = User::query()->create([
            'name' => 'Refund Concurrent Settlement Customer',
            'email' => 'refund-concurrent-settlement@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => '987658',
            'paid_at' => now(),
            'metadata' => ['gateway_mode' => 'test'],
        ])->save();

        $refund = PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'refund-concurrent-terminal-reconciliation',
            'amount_minor' => 2500,
            'reason' => 'Concurrent settlement reconciliation test',
            'status' => 'provider_pending',
            'provider_refund_reference' => '123458',
            'provider_status' => 'pending',
            'metadata' => ['currency' => 'NGN'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
        ]);

        Http::fake(function ($request) use ($refund) {
            if (! str_ends_with($request->url(), '/refund/123458')) {
                return Http::response(['status' => false, 'message' => 'Unexpected test request'], 404);
            }

            // Simulate another verified worker settling the refund while this
            // reconciliation request is in flight.
            $refund->forceFill(['status' => 'succeeded', 'settled_at' => now()])->save();

            return Http::response([
                'status' => true,
                'data' => [
                    'id' => 123458,
                    'transaction' => 987658,
                    'amount' => 9999,
                    'currency' => 'NGN',
                    'status' => 'processed',
                ],
            ], 200);
        });

        $result = app(\App\Services\Payments\PaymentRefundService::class)
            ->reconcileProviderStatus($refund->public_id);

        $this->assertSame('skipped', $result['outcome']);
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame('succeeded', $refund->fresh()->status);
    }

    public function test_stale_review_required_refund_is_reconciled_by_scheduled_reconciliation(): void
    {
        Http::fake([
            'api.paystack.co/refund/123459' => Http::response([
                'status' => true,
                'data' => [
                    'id' => 123459,
                    'transaction' => 987657,
                    'amount' => 2500,
                    'currency' => 'NGN',
                    'status' => 'processed',
                ],
            ], 200),
        ]);
        config(['services.paystack.secret_key' => 'sk_test_refund_reconcile']);

        $user = User::query()->create([
            'name' => 'Stale Review Refund Customer',
            'email' => 'stale-review-refund@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => '987657',
            'paid_at' => now(),
        ])->save();

        $refund = PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'refund-reconcile-review-required',
            'amount_minor' => 2500,
            'reason' => 'Scheduled review recovery test',
            'status' => 'review_required',
            'status_changed_at' => now()->subHour(),
            'provider_refund_reference' => '123459',
            'provider_status' => 'needs-attention',
            'metadata' => ['currency' => 'NGN'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $stats = app(PaymentRefundReconciliationService::class)->reconcile();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['reconciled']);
        $this->assertSame('succeeded', $refund->fresh()->status);
        $this->assertSame('processed', $refund->fresh()->provider_status);
        Http::assertSentCount(1);
    }

    public function test_unavailable_provider_refund_lookup_keeps_refund_pending_for_retry(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_refund_reconcile']);
        Http::fake(['api.paystack.co/refund/123458' => Http::response([
            'status' => false,
            'message' => 'Temporary provider outage',
        ], 503)]);

        $user = User::query()->create([
            'name' => 'Refund Unavailable Customer',
            'email' => 'refund-unavailable@example.test',
            'password' => 'test-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => '987656',
            'paid_at' => now(),
        ])->save();

        $refund = PaymentRefund::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'payment_intent_id' => $intent->id,
            'requested_by_user_id' => $user->id,
            'idempotency_key' => 'refund-reconcile-unavailable',
            'amount_minor' => 2500,
            'reason' => 'Provider unavailable test',
            'status' => 'provider_pending',
            'provider_refund_reference' => '123458',
            'provider_status' => 'pending',
            'metadata' => ['currency' => 'NGN'],
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
            'status_changed_at' => now()->subHour(),
        ]);

        $stats = app(PaymentRefundReconciliationService::class)->reconcile();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['unavailable']);
        $this->assertSame('provider_pending', $refund->fresh()->status);
        $this->assertSame('pending', $refund->fresh()->provider_status);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
    }
}
