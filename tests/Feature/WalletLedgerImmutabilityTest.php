<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class WalletLedgerImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_posted_ledger_entry_cannot_be_updated(): void
    {
        $user = User::query()->create([
            'name' => 'Ledger Integrity Customer',
            'email' => 'ledger-integrity-update@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $transaction = app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 25000,
            idempotencyKey: 'ledger-immutable-update-test',
            description: 'Integrity test credit',
        );
        $entry = $transaction->ledgerEntry()->firstOrFail();

        try {
            $entry->forceFill(['memo' => 'tampered after posting'])->save();
            $this->fail('A posted ledger entry was unexpectedly updated.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Wallet ledger entries are append-only and cannot be updated.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'id' => $entry->id,
            'memo' => 'Integrity test credit',
            'amount_minor' => 25000,
        ]);
    }

    public function test_posted_ledger_entry_cannot_be_deleted(): void
    {
        $user = User::query()->create([
            'name' => 'Ledger Integrity Customer',
            'email' => 'ledger-integrity-delete@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);
        $transaction = app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 35000,
            idempotencyKey: 'ledger-immutable-delete-test',
            description: 'Integrity test credit',
        );
        $entry = $transaction->ledgerEntry()->firstOrFail();

        try {
            $entry->delete();
            $this->fail('A posted ledger entry was unexpectedly deleted.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Wallet ledger entries are append-only and cannot be deleted.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseHas('wallet_ledger_entries', [
            'id' => $entry->id,
            'wallet_transaction_id' => $transaction->id,
            'amount_minor' => 35000,
        ]);
    }
}
