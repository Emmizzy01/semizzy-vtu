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

        // Hold both wallet locks until both workers have had time to pass the
        // initial idempotency lookup. They must recheck after the locks release.
        DB::beginTransaction();
        Wallet::query()
            ->whereIn('id', [$senderWallet->id, $recipientWallet->id])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                DB::rollBack();
                $this->fail('Unable to start a concurrent wallet transfer worker.');
            }

            if ($pid === 0) {
                try {
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
}
