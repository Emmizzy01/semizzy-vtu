<?php

namespace Tests\Feature;

use App\Exceptions\Wallet\IdempotencyConflict;
use App\Models\User;
use App\Models\WalletLedgerEntry;
use App\Models\WalletTransfer;
use App\Services\Wallets\PeerWalletTransferService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class PeerWalletTransferTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name, string $email, string $username): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'password' => 'not-a-real-password',
        ]);
    }

    public function test_transfer_debits_sender_and_credits_recipient_once_with_ledger_entries(): void
    {
        $sender = $this->makeUser('Sender', 'sender@example.test', 'sender1');
        $recipient = $this->makeUser('Recipient', 'recipient@example.test', 'recipient1');
        $wallets = app(WalletAccountService::class);
        $senderWallet = $wallets->openForUser($sender);
        $recipientWallet = $wallets->openForUser($recipient);
        app(WalletLedgerService::class)->post($senderWallet->id, 'credit', 50000, 'p2p-test-seed-001');

        $service = app(PeerWalletTransferService::class);
        $first = $service->transfer($sender, 'recipient1', 12500, 'p2p-idempotency-001');
        $again = $service->transfer($sender, 'recipient1', 12500, 'p2p-idempotency-001');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(37500, (int) $senderWallet->fresh()->balance_minor);
        $this->assertSame(12500, (int) $recipientWallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transfers', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $senderWallet->id)->where('entry_type', 'debit')->count());
        $this->assertSame(1, WalletLedgerEntry::query()->where('wallet_id', $recipientWallet->id)->where('entry_type', 'credit')->count());
    }

    public function test_transfer_rejects_self_transfer(): void
    {
        $sender = $this->makeUser('Sender', 'self@example.test', 'selfsender');

        $this->expectException(InvalidArgumentException::class);
        app(PeerWalletTransferService::class)->transfer($sender, 'selfsender', 1000, 'p2p-self-key-001');
    }

    public function test_transfer_rejects_reusing_key_with_different_payload(): void
    {
        $sender = $this->makeUser('Sender', 'conflict-sender@example.test', 'conflictsender');
        $recipient = $this->makeUser('Recipient', 'conflict-recipient@example.test', 'conflictrecipient');
        $wallets = app(WalletAccountService::class);
        $senderWallet = $wallets->openForUser($sender);
        app(WalletLedgerService::class)->post($senderWallet->id, 'credit', 50000, 'p2p-conflict-seed-001');
        $service = app(PeerWalletTransferService::class);
        $service->transfer($sender, 'conflictrecipient', 10000, 'p2p-conflict-key-001');

        $this->expectException(IdempotencyConflict::class);
        $service->transfer($sender, 'conflictrecipient', 11000, 'p2p-conflict-key-001');
    }

    public function test_insufficient_funds_rolls_back_both_sides_of_transfer(): void
    {
        $sender = $this->makeUser('Sender', 'poor-sender@example.test', 'poorsender');
        $recipient = $this->makeUser('Recipient', 'poor-recipient@example.test', 'poorrecipient');
        $wallets = app(WalletAccountService::class);
        $senderWallet = $wallets->openForUser($sender);
        $recipientWallet = $wallets->openForUser($recipient);
        app(WalletLedgerService::class)->post($senderWallet->id, 'credit', 500, 'p2p-poor-seed-001');

        try {
            app(PeerWalletTransferService::class)->transfer($sender, 'poorrecipient', 1000, 'p2p-poor-key-001');
            $this->fail('Insufficient funds should reject the transfer.');
        } catch (\App\Exceptions\Wallet\InsufficientWalletFunds) {
            $this->assertSame(500, (int) $senderWallet->fresh()->balance_minor);
            $this->assertSame(0, (int) $recipientWallet->fresh()->balance_minor);
            $this->assertDatabaseCount('wallet_transfers', 0);
        }
    }
}
