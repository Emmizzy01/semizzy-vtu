<?php

namespace Tests\Feature;

use App\Models\PaymentReconciliationCase;
use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Payments\PaymentReconciliationService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.paystack.secret_key' => 'sk_test_reconciliation_secret',
            'services.payments.reconciliation_grace_minutes' => 5,
        ]);
    }

    private function pendingIntent(int $amount = 25000)
    {
        $user = User::query()->create([
            'name' => 'Reconciliation Customer',
            'email' => 'reconcile@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, $amount, 'paystack');
        $intent->forceFill(['created_at' => now()->subMinutes(10), 'expires_at' => now()->addMinutes(20)])->save();
        return [$wallet, $intent];
    }

    public function test_reconciler_credits_a_verified_success_when_webhook_was_missed(): void
    {
        [$wallet, $intent] = $this->pendingIntent();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 54321, 'status' => 'success', 'reference' => $intent->reference,
                'amount' => 25000, 'currency' => 'NGN',
            ],
        ], 200)]);

        $stats = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(25000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame(1, $stats['credited']);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_reconciler_records_mismatched_success_without_crediting(): void
    {
        [$wallet, $intent] = $this->pendingIntent();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 54322, 'status' => 'success', 'reference' => $intent->reference,
                'amount' => 99999, 'currency' => 'NGN',
            ],
        ], 200)]);

        $stats = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(1, $stats['exceptions']);
        $this->assertDatabaseHas('payment_reconciliation_cases', [
            'reference' => $intent->reference,
            'case_type' => 'successful_payment_mismatch',
            'status' => 'open',
        ]);
    }

    public function test_expired_success_is_flagged_for_review_not_auto_credited(): void
    {
        [$wallet, $intent] = $this->pendingIntent();
        $intent->forceFill(['expires_at' => now()->subMinute()])->save();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 54323, 'status' => 'success', 'reference' => $intent->reference,
                'amount' => 25000, 'currency' => 'NGN',
            ],
        ], 200)]);

        $stats = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(1, $stats['exceptions']);
        $this->assertDatabaseHas('payment_reconciliation_cases', [
            'reference' => $intent->reference,
            'case_type' => 'late_success_review',
        ]);
    }

    public function test_provider_failed_payment_is_marked_failed_and_recorded(): void
    {
        [$wallet, $intent] = $this->pendingIntent();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'data' => [
                'id' => 54324, 'status' => 'failed', 'reference' => $intent->reference,
                'amount' => 25000, 'currency' => 'NGN',
            ],
        ], 200)]);

        $stats = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1, $stats['failed']);
        $this->assertDatabaseHas('payment_reconciliation_cases', [
            'reference' => $intent->reference,
            'case_type' => 'provider_failed',
        ]);
    }

    public function test_provider_verification_unavailable_never_changes_intent_or_wallet_balance(): void
    {
        [$wallet, $intent] = $this->pendingIntent();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response([
            'status' => false,
            'message' => 'Temporary provider outage',
        ], 503)]);

        $stats = app(PaymentReconciliationService::class)->reconcile();

        $this->assertSame(1, $stats['checked']);
        $this->assertSame(1, $stats['unavailable']);
        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
