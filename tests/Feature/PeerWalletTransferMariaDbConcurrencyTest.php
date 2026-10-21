<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransfer;
use App\Services\Wallets\PeerWalletTransferService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeerWalletTransferMariaDbConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_same_key_transfer_posts_only_one_debit_and_credit(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This test requires the MariaDB/MySQL CI service.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('This test requires PHP pcntl process control.');
        }

        $sender = User::query()->create([
            'name' => 'Concurrent Transfer Sender',
            'email' => 'concurrent-transfer-sender@example.test',
            'password' => 'not-a-real-password',
        ]);
        $sender->forceFill(['username' => 'concurrent_sender'])->save();
        $recipient = User::query()->create([
            'name' => 'Concurrent Transfer Recipient',
            'email' => 'concurrent-transfer-recipient@example.test',
            'password' => 'not-a-real-password',
        ]);
        $recipient->forceFill(['username' => 'concurrent_recipient'])->save();

        $wallets = app(WalletAccountService::class);
        $senderWallet = $wallets->openForUser($sender);
        $recipientWallet = $wallets->openForUser($recipient);
        app(WalletLedgerService::class)->post(
            walletId: (int) $senderWallet->getKey(),
            type: 'credit',
            amountMinor: 5000,
            idempotencyKey: 'concurrent-p2p-seed-001',
        );

        // Fork only after disconnecting the parent's PDO. Forking while a
        // transaction is open duplicates the MySQL socket into each worker;
        // a child can then send COM_QUIT on the shared connection and kill the
        // parent's transaction. Workers reconnect independently below.
        DB::disconnect();

        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to start a concurrent wallet transfer worker.');
            }

            if ($pid === 0) {
                try {
                    // Let the parent acquire the wallet locks before either
                    // request enters the service. Each worker opens a fresh PDO.
                    usleep(250_000);
                    DB::purge();
                    app(PeerWalletTransferService::class)->transfer(
                        User::query()->findOrFail($sender->id),
                        'concurrent_recipient',
                        1000,
                        'concurrent-p2p-same-key-001',
                    );
                    exit(0);
                } catch (\Throwable) {
                    exit(20);
                }
            }

            $children[] = $pid;
        }

        // Acquire locks after fork so workers do not inherit this PDO/socket.
        DB::beginTransaction();
        Wallet::query()
            ->whereIn('id', [$senderWallet->id, $recipientWallet->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        usleep(1_000_000);
        DB::commit();

        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
        }

        sort($codes);
        $this->assertSame([0, 0], $codes, 'Both identical requests should resolve successfully to the same transfer.');
        DB::purge();

        $this->assertSame(4000, (int) $senderWallet->fresh()->balance_minor);
        $this->assertSame(1000, (int) $recipientWallet->fresh()->balance_minor);
        $this->assertSame(1, WalletTransfer::query()->where('sender_user_id', $sender->id)->count());
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
    }

    public function test_parallel_same_key_different_payload_allows_only_one_transfer(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This test requires the MariaDB/MySQL CI service.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('This test requires PHP pcntl process control.');
        }

        $sender = User::query()->create([
            'name' => 'Concurrent Conflict Sender',
            'email' => 'concurrent-conflict-sender@example.test',
            'password' => 'not-a-real-password',
        ]);
        $sender->forceFill(['username' => 'concurrent_conflict_sender'])->save();
        $recipient = User::query()->create([
            'name' => 'Concurrent Conflict Recipient',
            'email' => 'concurrent-conflict-recipient@example.test',
            'password' => 'not-a-real-password',
        ]);
        $recipient->forceFill(['username' => 'concurrent_conflict_recipient'])->save();

        $wallets = app(WalletAccountService::class);
        $senderWallet = $wallets->openForUser($sender);
        $recipientWallet = $wallets->openForUser($recipient);
        app(WalletLedgerService::class)->post(
            walletId: (int) $senderWallet->getKey(),
            type: 'credit',
            amountMinor: 5000,
            idempotencyKey: 'concurrent-p2p-conflict-seed-001',
        );

        DB::disconnect();

        $children = [];
        foreach ([1000, 1500] as $amount) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to start a concurrent wallet transfer worker.');
            }

            if ($pid === 0) {
                try {
                    usleep(250_000);
                    DB::purge();
                    app(PeerWalletTransferService::class)->transfer(
                        User::query()->findOrFail($sender->id),
                        'concurrent_conflict_recipient',
                        $amount,
                        'concurrent-p2p-conflict-key-001',
                    );
                    exit(0);
                } catch (\App\Exceptions\Wallet\IdempotencyConflict) {
                    exit(21);
                } catch (\Throwable) {
                    exit(20);
                }
            }

            $children[] = $pid;
        }

        DB::beginTransaction();
        Wallet::query()
            ->whereIn('id', [$senderWallet->id, $recipientWallet->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        usleep(1_000_000);
        DB::commit();

        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
        }

        sort($codes);
        $this->assertSame([0, 21], $codes, 'One request should succeed and the changed-payload request should receive an idempotency conflict.');

        DB::purge();
        $transfer = WalletTransfer::query()->where('sender_user_id', $sender->id)->sole();
        $amount = (int) $transfer->amount_minor;

        $this->assertContains($amount, [1000, 1500]);
        $this->assertSame(5000 - $amount, (int) $senderWallet->fresh()->balance_minor);
        $this->assertSame($amount, (int) $recipientWallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
    }

}
