<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Payments\PaymentRefundService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentRefundMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('Refund concurrency tests require a dedicated MySQL database whose name ends in _test.');
        }
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('Refund concurrency tests require proc_open.');
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

    public function test_competing_refund_approvals_submit_to_provider_and_reserve_wallet_once(): void
    {
        $customer = User::query()->create([
            'name' => 'Concurrent Refund Customer',
            'email' => 'refund-race-customer-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);
        $approver = User::query()->create([
            'name' => 'Concurrent Refund Approver',
            'email' => 'refund-race-approver-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->id,
            type: 'credit',
            amountMinor: 10000,
            idempotencyKey: 'refund-race-seed:'.$customer->id,
            description: 'Concurrency test opening balance',
        );
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => 'refund-race-txn-'.$customer->id,
            'paid_at' => now(),
        ])->save();

        $refund = app(PaymentRefundService::class)->request(
            $customer,
            $intent->public_id,
            2500,
            'Concurrent approval regression',
            'refund-race-key-'.$customer->id,
        );

        $state = tempnam(sys_get_temp_dir(), 'semizzy-refund-race-');
        if ($state === false) {
            $this->fail('Could not create refund concurrency state file.');
        }
        file_put_contents($state, json_encode(['submissions' => 0], JSON_THROW_ON_ERROR));

        $worker = base_path('tests/Support/payment_refund_concurrency_worker.php');
        $args = [$state, $refund->public_id, (string) $approver->id];
        $processes = [];
        foreach ([1, 2] as $_) {
            $pipes = [];
            $process = proc_open(array_merge([PHP_BINARY, $worker], $args), [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, base_path());
            $this->assertIsResource($process, 'Could not start independent refund approval worker.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $results[] = [
                'exit' => proc_close($process),
                'stdout' => trim((string) $stdout),
                'stderr' => trim((string) $stderr),
            ];
        }

        try {
            foreach ($results as $result) {
                $this->assertContains($result['exit'], [0, 21], 'Refund worker failed unexpectedly: '.$result['stderr']);
            }
            $this->assertSame(1, count(array_filter($results, static fn (array $result): bool => $result['exit'] === 0)));
            $stateData = json_decode((string) file_get_contents($state), true);
            $this->assertSame(1, (int) ($stateData['submissions'] ?? 0), 'Concurrent approvals must submit to the provider once.');
            $this->assertSame('provider_pending', $refund->fresh()->status);
            $this->assertSame(7500, (int) $wallet->fresh()->balance_minor);
            $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Expected one seed credit and one refund reservation debit.');
            $this->assertSame(2, DB::table('wallet_ledger_entries')->count(), 'Expected exactly one refund reservation ledger posting.');
        } finally {
            @unlink($state);
        }
    }
}
