<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalletBalanceLimitConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_parallel_mariadb_credits_cannot_both_cross_the_wallet_cap(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This test requires the MariaDB/MySQL CI service.');
        }

        if (! function_exists('pcntl_fork') || ! function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('This test requires PHP pcntl process control.');
        }

        $user = User::query()->create([
            'name' => 'Concurrent Cap Customer',
            'email' => 'concurrent-cap@example.test',
            'password' => 'not-a-real-password',
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($user);

        app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 800,
            idempotencyKey: 'concurrent-cap-seed',
        );

        UserLevelPolicy::query()->where('level', 'level_1')->update([
            'wallet_balance_limit_minor' => 1000,
            'is_active' => true,
        ]);

        // Fork only after fixture writes are committed. Each child drops its
        // inherited PDO connection and opens its own independent DB session.
        DB::disconnect();
        $children = [];

        foreach (['parallel-cap-credit-a', 'parallel-cap-credit-b'] as $key) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to start a concurrent wallet-credit worker.');
            }

            if ($pid === 0) {
                try {
                    DB::purge();
                    app(WalletLedgerService::class)->post(
                        walletId: $wallet->id,
                        type: 'credit',
                        amountMinor: 200,
                        idempotencyKey: $key,
                    );
                    exit(0); // credit accepted
                } catch (\DomainException) {
                    exit(10); // cap enforced after acquiring the wallet lock
                } catch (\Throwable) {
                    exit(20); // unexpected worker failure
                }
            }

            $children[] = $pid;
        }

        $codes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $codes[] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 99;
        }

        sort($codes);
        $this->assertSame([0, 10], $codes, 'Exactly one concurrent credit must succeed and the other must be rejected by the cap.');

        DB::purge();
        $this->assertSame(1000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        $this->assertDatabaseCount('wallet_transactions', 2);
    }
}
