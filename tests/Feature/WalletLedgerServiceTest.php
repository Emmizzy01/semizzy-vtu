<?php

namespace Tests\Feature;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Exceptions\Wallet\InsufficientWalletFunds;
use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Models\Wallet;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransaction;
use DomainException;
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
    public function test_credit_can_reach_exact_user_level_wallet_balance_limit(): void
    {
        $user = User::query()->create(['name' => 'Exact Cap Customer', 'email' => 'exact-cap@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 400, 'exact-cap-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update(['wallet_balance_limit_minor' => 1000, 'is_active' => true]);

        app(WalletLedgerService::class)->post($wallet->id, 'credit', 600, 'exact-cap-credit');

        $this->assertSame(1000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

    public function test_competing_credits_recheck_balance_after_the_first_credit(): void
    {
        $user = User::query()->create(['name' => 'Competing Credit Customer', 'email' => 'competing-credit@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 800, 'competing-credit-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update(['wallet_balance_limit_minor' => 1000, 'is_active' => true]);

        app(WalletLedgerService::class)->post($wallet->id, 'credit', 100, 'competing-credit-a');
        try {
            app(WalletLedgerService::class)->post($wallet->id, 'credit', 200, 'competing-credit-b');
            $this->fail('The second credit must recheck the updated balance and be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('account level would be exceeded', $exception->getMessage());
        }

        $this->assertSame(900, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

    public function test_credit_over_user_level_wallet_balance_limit_is_rejected_atomically(): void
    {
        $user = User::query()->create(['name' => 'Over Cap Customer', 'email' => 'over-cap@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 900, 'over-cap-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update(['wallet_balance_limit_minor' => 1000, 'is_active' => true]);

        try {
            app(WalletLedgerService::class)->post($wallet->id, 'credit', 101, 'over-cap-credit');
            $this->fail('A credit that exceeds the configured wallet balance limit must be rejected.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('account level would be exceeded', $exception->getMessage());
        }

        $this->assertSame(900, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }


    public function test_same_debit_idempotency_key_and_payload_cannot_debit_twice(): void
    {
        $user = User::query()->create([
            'name' => 'Idempotent Debit Customer',
            'email' => 'idempotent-debit@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $service = app(WalletLedgerService::class);
        $service->post($wallet->id, 'credit', 1000, 'idempotent-debit-seed');

        $first = $service->post($wallet->id, 'debit', 700, 'idempotent-debit-key');
        $second = $service->post($wallet->id, 'debit', 700, 'idempotent-debit-key');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(300, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(300, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

    public function test_debit_idempotency_key_reuse_with_changed_amount_is_rejected_without_second_debit(): void
    {
        $user = User::query()->create([
            'name' => 'Debit Conflict Customer',
            'email' => 'debit-conflict@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $service = app(WalletLedgerService::class);
        $service->post($wallet->id, 'credit', 1000, 'debit-conflict-seed');
        $service->post($wallet->id, 'debit', 300, 'debit-conflict-key');

        try {
            $service->post($wallet->id, 'debit', 400, 'debit-conflict-key');
            $this->fail('Reusing a debit idempotency key with a different amount must be rejected.');
        } catch (IdempotencyConflict) {
            $this->assertSame(700, (int) $wallet->fresh()->balance_minor);
            $this->assertSame(2, WalletTransaction::query()->count());
            $this->assertSame(2, WalletLedgerEntry::query()->count());
        }
    }


    public function test_wallet_balance_and_ledger_running_balances_remain_consistent_after_credits_and_debits(): void
    {
        $user = User::query()->create([
            'name' => 'Ledger Reconciliation Customer',
            'email' => 'ledger-reconciliation@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $ledger = app(WalletLedgerService::class);

        $ledger->post($wallet->id, 'credit', 2500, 'ledger-reconcile-credit-1');
        $ledger->post($wallet->id, 'debit', 700, 'ledger-reconcile-debit-1');
        $ledger->post($wallet->id, 'credit', 1250, 'ledger-reconcile-credit-2');
        $ledger->post($wallet->id, 'debit', 300, 'ledger-reconcile-debit-2');

        $runningBalance = 0;
        $entries = WalletLedgerEntry::query()
            ->where('wallet_id', $wallet->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(4, $entries);

        foreach ($entries as $entry) {
            $runningBalance += $entry->entry_type === 'credit'
                ? (int) $entry->amount_minor
                : -(int) $entry->amount_minor;

            $this->assertSame($runningBalance, (int) $entry->balance_after_minor);
            $this->assertSame($runningBalance, (int) $entry->transaction()->firstOrFail()->balance_after_minor);
        }

        $freshWallet = $wallet->fresh();
        $this->assertSame(2750, $runningBalance);
        $this->assertSame($runningBalance, (int) $freshWallet->balance_minor);
        $this->assertSame($runningBalance, (int) $freshWallet->available_balance_minor);
        $this->assertSame(4, WalletTransaction::query()->where('wallet_id', $wallet->id)->where('status', 'posted')->count());
    }

    public function test_wallet_balance_cap_remains_enforced_when_account_tier_is_disabled(): void
    {
        $user = User::query()->create(['name' => 'Disabled Cap Customer', 'email' => 'disabled-cap@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 900, 'disabled-cap-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update([
            'wallet_balance_limit_minor' => 1000,
            'is_active' => false,
        ]);

        try {
            app(WalletLedgerService::class)->post($wallet->id, 'credit', 101, 'disabled-cap-overflow');
            $this->fail('Disabling a tier must not disable its wallet balance cap.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('account level would be exceeded', $exception->getMessage());
        }

        $this->assertSame(900, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(900, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }


    public function test_wallet_balance_caps_are_consistent_for_all_four_account_tiers(): void
    {
        foreach (['level_1', 'level_2', 'level_3', 'level_4'] as $index => $level) {
            $user = User::query()->create([
                'name' => 'Tier Cap Customer '.$index,
                'email' => 'tier-cap-'.$index.'@example.test',
                'password' => 'not-a-real-password',
            ]);
            $user->forceFill(['user_level' => $level])->save();
            $wallet = app(WalletAccountService::class)->openForUser($user);
            UserLevelPolicy::query()->where('level', $level)->update([
                'wallet_balance_limit_minor' => 1000,
                'is_active' => false,
            ]);
            app(WalletLedgerService::class)->post($wallet->id, 'credit', 900, 'tier-cap-seed-'.$level);

            try {
                app(WalletLedgerService::class)->post($wallet->id, 'credit', 101, 'tier-cap-overflow-'.$level);
                $this->fail('Wallet cap must be enforced for '.$level.', even when disabled.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('account level would be exceeded', $exception->getMessage());
            }

            $this->assertSame(900, (int) $wallet->fresh()->balance_minor, 'Balance changed for '.$level);
            $this->assertSame(900, (int) $wallet->fresh()->available_balance_minor, 'Available balance changed for '.$level);
        }

        $this->assertDatabaseCount('wallet_transactions', 4);
        $this->assertDatabaseCount('wallet_ledger_entries', 4);
    }


    public function test_explicit_financial_recovery_can_restore_funds_above_cap_for_disabled_tier(): void
    {
        $user = User::query()->create([
            'name' => 'Recovery Cap Customer',
            'email' => 'recovery-cap@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill(['user_level' => 'level_2'])->save();
        $wallet = app(WalletAccountService::class)->openForUser($user);
        UserLevelPolicy::query()->where('level', 'level_2')->update([
            'wallet_balance_limit_minor' => 1000,
            'is_active' => false,
        ]);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 900, 'recovery-cap-seed');

        app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 101,
            idempotencyKey: 'recovery-cap-compensation',
            description: 'Verified financial recovery',
            isFinancialRecovery: true,
        );

        $this->assertSame(1001, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1001, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

}
