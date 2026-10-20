<?php

namespace Tests\Feature;

use App\Models\BankBeneficiary;
use App\Models\User;
use App\Models\Wallet;
use App\Services\IntegrationSettings;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankPayoutMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('Payout concurrency tests require a dedicated MySQL database whose name ends in _test.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Payout concurrency tests require proc_open.');
        }

        $this->usesDedicatedTestDatabase = true;
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::purge('mysql');
    }

    protected function tearDown(): void
    {
        if ($this->usesDedicatedTestDatabase) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::purge('mysql');
            RefreshDatabaseState::$migrated = false;
        }

        parent::tearDown();
    }

    public function test_competing_payout_requests_with_same_key_debit_and_submit_only_once(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent Payout Customer',
            'email' => 'payout-race-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);

        $beneficiary = BankBeneficiary::query()->create([
            'user_id' => $user->getKey(),
            'bank_code' => '999',
            'bank_name' => 'Concurrency Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $user->name,
            'provider_recipient_code' => 'RCP_race_'.Str::uuid(),
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $wallet = Wallet::query()->create([
            'user_id' => $user->getKey(),
            'currency' => 'NGN',
            'status' => 'active',
            'balance_minor' => 0,
            'available_balance_minor' => 0,
            'version' => 0,
        ]);

        app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: 50000,
            idempotencyKey: 'payout-race-funding:'.$user->getKey(),
            reference: 'payout-race-funding:'.$user->getKey(),
            description: 'Concurrency test opening balance',
            actorUserId: (int) $user->getKey(),
        );
        app(IntegrationSettings::class)->set('paystack.transfers_enabled', '1', (int) $user->getKey());

        $state = tempnam(sys_get_temp_dir(), 'semizzy-payout-race-');
        if ($state === false) {
            $this->fail('Could not create payout concurrency state file.');
        }
        file_put_contents($state, json_encode(['submissions' => 0], JSON_THROW_ON_ERROR));

        $worker = base_path('tests/Support/bank_payout_concurrency_worker.php');
        $args = ['request', $state, (string) $user->getKey(), (string) $beneficiary->getKey(), '12500', 'same-payout-race-key'];
        $processes = [];

        // Start both independent PHP processes before collecting either result.
        foreach ([1, 2] as $_) {
            $pipes = [];
            $process = proc_open(array_merge([PHP_BINARY, $worker], $args), [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, base_path());
            $this->assertIsResource($process, 'Could not start independent payout worker.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $results[] = ['exit' => proc_close($process), 'stdout' => trim((string) $stdout), 'stderr' => trim((string) $stderr)];
        }

        try {
            foreach ($results as $result) {
                $this->assertSame(0, $result['exit'], 'Independent payout worker failed: '.$result['stderr']);
            }

            $this->assertSame(1, DB::table('bank_payouts')->where('user_id', $user->getKey())->count());
            $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'debit')->count());
            $this->assertSame(2, DB::table('wallet_ledger_entries')->count(), 'Expected one opening credit and one payout debit.');
            $this->assertSame(37500, (int) $wallet->fresh()->balance_minor);
            $stateData = json_decode((string) file_get_contents($state), true);
            $this->assertSame(1, (int) ($stateData['submissions'] ?? 0), 'Concurrent duplicates must result in one provider submission.');
        } finally {
            @unlink($state);
        }
    }
}
