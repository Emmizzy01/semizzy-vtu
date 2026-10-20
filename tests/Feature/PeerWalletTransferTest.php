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
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill(['username' => $username])->save();
        return $user;
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

    public function test_http_transfer_endpoint_rejects_wrong_pin_before_creating_transfer(): void
    {
        $sender = $this->makeUser('HTTP Sender', 'http-sender@example.test', 'httpsender');
        $recipient = $this->makeUser('HTTP Recipient', 'http-recipient@example.test', 'httpreceiver');
        $sender->forceFill([
            'transaction_pin_hash' => \Illuminate\Support\Facades\Hash::make('4928'),
        ])->save();

        $this->actingAs($sender)->postJson('/wallet/transfers', [
            'recipient' => 'httpreceiver',
            'amount_minor' => 2500,
            'transaction_pin' => '1111',
            'idempotency_key' => 'http-transfer-wrong-pin-001',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Transaction PIN is incorrect.');

        $this->assertDatabaseCount('wallet_transfers', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $sender->id,
            'event_type' => 'pin_verification',
            'outcome' => 'failed',
        ]);
    }


    public function test_transfer_history_is_scoped_to_the_authenticated_user(): void
    {
        $sender = $this->makeUser('History Sender', 'history-sender@example.test', 'historysender');
        $recipient = $this->makeUser('History Recipient', 'history-recipient@example.test', 'historyrecipient');
        $outsider = $this->makeUser('History Outsider', 'history-outsider@example.test', 'historyoutsider');
        $wallets = app(WalletAccountService::class);
        $senderWallet = $wallets->openForUser($sender);
        $recipientWallet = $wallets->openForUser($recipient);
        app(WalletLedgerService::class)->post($senderWallet->id, 'credit', 5000, 'history-transfer-seed-001');
        app(PeerWalletTransferService::class)->transfer($sender, 'historyrecipient', 1000, 'history-transfer-key-001');

        $this->actingAs($outsider)->getJson('/wallet/transfers/history')
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonCount(0, 'data');
        $this->actingAs($sender)->getJson('/wallet/transfers/history')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.direction', 'sent');
        $this->actingAs($recipient)->getJson('/wallet/transfers/history')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.direction', 'received');
    }
}
