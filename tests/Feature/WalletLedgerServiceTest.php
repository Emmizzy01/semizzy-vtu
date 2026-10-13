<?php

namespace Tests\Feature;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletLedgerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_posting_a_credit_updates_balance_and_writes_ledger_and_audit(): void
    {
        $user = User::query()->create([
            'name' => 'Test Customer',
            'email' => 'customer@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);

        $transaction = app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 2500,
            idempotencyKey: 'funding-event-0001',
            reference: 'test-funding-0001',
            description: 'Verified test funding',
        );

        $this->assertSame('posted', $transaction->status);
        $this->assertSame(2500, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(2500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseHas('wallet_audit_events', [
            'wallet_transaction_id' => $transaction->id,
            'event_type' => 'wallet.transaction.posted',
            'outcome' => 'success',
        ]);
    }

    public function test_same_idempotency_key_and_payload_does_not_post_twice(): void
    {
        $user = User::query()->create([
            'name' => 'Test Customer',
            'email' => 'idempotent@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $service = app(WalletLedgerService::class);

        $first = $service->post($wallet->id, 'credit', 500, 'same-key-1');
        $second = $service->post($wallet->id, 'credit', 500, 'same-key-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_reusing_an_idempotency_key_with_a_different_amount_is_rejected(): void
    {
        $user = User::query()->create([
            'name' => 'Test Customer',
            'email' => 'conflict@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $service = app(WalletLedgerService::class);

        $service->post($wallet->id, 'credit', 500, 'conflicting-key-1');

        $this->expectException(IdempotencyConflict::class);
        $service->post($wallet->id, 'credit', 501, 'conflicting-key-1');
    }

    public function test_debit_cannot_overdraw_wallet_and_does_not_create_a_ledger_entry(): void
    {
        $user = User::query()->create([
            'name' => 'Test Customer',
            'email' => 'debit@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $service = app(WalletLedgerService::class);

        try {
            $service->post($wallet->id, 'debit', 100, 'debit-too-much-1');
            $this->fail('Expected insufficient funds exception.');
        } catch (InsufficientWalletFunds) {
            $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
            $this->assertDatabaseCount('wallet_transactions', 0);
            $this->assertDatabaseCount('wallet_ledger_entries', 0);
            $this->assertDatabaseHas('wallet_audit_events', [
                'wallet_id' => $wallet->id,
                'event_type' => 'wallet.transaction.rejected',
                'outcome' => 'rejected',
            ]);
        }
    }

    public function test_debit_reduces_balance_in_minor_units(): void
    {
        $user = User::query()->create([
            'name' => 'Test Customer',
            'email' => 'debit-success@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $service = app(WalletLedgerService::class);
        $service->post($wallet->id, 'credit', 1200, 'seed-credit-1');

        $debit = $service->post($wallet->id, 'debit', 450, 'purchase-debit-1');

        $this->assertSame('posted', $debit->status);
        $this->assertSame(750, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(2, WalletTransaction::query()->count());
        $this->assertSame(2, WalletLedgerEntry::query()->count());
    }
}
