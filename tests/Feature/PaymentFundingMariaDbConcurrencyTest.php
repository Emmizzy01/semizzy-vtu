<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentFundingMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Independent workers share committed rows. Never reset a non-test database.
        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('Payment webhook concurrency tests require a dedicated MySQL database ending in _test.');
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

    public function test_concurrent_duplicate_event_deliveries_credit_the_wallet_once(): void
    {
        [$wallet, $intent] = $this->preparedFundingIntent();
        $state = $this->newStateFile();

        $results = $this->runWorkers([
            [$state, 'evt_concurrent_same', $intent->reference, 'tx_concurrent_same', '25000'],
            [$state, 'evt_concurrent_same', $intent->reference, 'tx_concurrent_same', '25000'],
        ]);

        $this->assertCount(2, $results);
        $decoded = array_map(fn (array $result): array => json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR), $results);
        $this->assertCount(1, array_filter($decoded, fn (array $result): bool => ! $result['duplicate']));
        $this->assertCount(1, array_filter($decoded, fn (array $result): bool => $result['duplicate']));
        $this->assertSame(25000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('payment_webhook_events', 1);
        @unlink($state);
    }

    public function test_concurrent_distinct_events_for_one_provider_transaction_credit_once(): void
    {
        [$wallet, $intent] = $this->preparedFundingIntent();
        $state = $this->newStateFile();

        $results = $this->runWorkers([
            [$state, 'evt_concurrent_a', $intent->reference, 'tx_concurrent_distinct', '25000'],
            [$state, 'evt_concurrent_b', $intent->reference, 'tx_concurrent_distinct', '25000'],
        ]);

        $this->assertCount(2, $results);
        $decoded = array_map(fn (array $result): array => json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR), $results);
        $this->assertCount(1, array_filter($decoded, fn (array $result): bool => $result['outcome'] === 'credited' && ! $result['duplicate']));
        $this->assertCount(1, array_filter($decoded, fn (array $result): bool => $result['outcome'] === 'duplicate_payment' && $result['duplicate']));
        $this->assertSame(25000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame('tx_concurrent_distinct', $intent->fresh()->provider_transaction_id);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('payment_webhook_events', 2);
        @unlink($state);
    }

    public function test_concurrent_paystack_webhook_and_reconciliation_events_credit_once(): void
    {
        config(['services.semizzy_payments.live_funding_enabled' => true]);
        [$wallet, $intent] = $this->preparedFundingIntent('paystack', 'live');
        $state = $this->newStateFile();
        $transactionId = 'tx_paystack_webhook_reconcile_race';

        $results = $this->runWorkers([
            [$state, 'evt_real_webhook_race', $intent->reference, $transactionId, '25000', 'paystack'],
            [$state, 'paystack-reconcile-success-'.$transactionId, $intent->reference, $transactionId, '25000', 'paystack'],
        ]);

        $decoded = array_map(fn (array $result): array => json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR), $results);
        $this->assertCount(1, array_filter($decoded, fn (array $result): bool => $result['outcome'] === 'credited' && ! $result['duplicate']));
        $this->assertCount(1, array_filter($decoded, fn (array $result): bool => $result['outcome'] === 'duplicate_payment' && $result['duplicate']));
        $this->assertSame(25000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame($transactionId, $intent->fresh()->provider_transaction_id);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('payment_webhook_events', 2);
        @unlink($state);
    }

    private function preparedFundingIntent(string $provider = 'semizzy', string $gatewayMode = 'test'): array
    {
        $user = User::query()->create([
            'name' => 'Payment Webhook Concurrency Customer',
            'email' => 'payment-webhook-concurrency-'.Str::uuid().'@example.test',
            'password' => bcrypt('test-only-password'),
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($user);
        $intent = app(PaymentIntentService::class)->create($wallet, 25000, $provider, $gatewayMode);

        return [$wallet->fresh(), $intent->fresh()];
    }

    private function newStateFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'semizzy-payment-race-');
        if ($path === false) {
            $this->fail('Could not create payment concurrency state file.');
        }

        file_put_contents($path, json_encode(['start' => false], JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * @param list<list<string>> $workers
     * @return list<array{exit:int,stdout:string,stderr:string}>
     */
    private function runWorkers(array $workers): array
    {
        $processes = [];
        $script = base_path('tests/Support/payment_webhook_concurrency_worker.php');

        foreach ($workers as $arguments) {
            $command = array_merge([PHP_BINARY, $script], $arguments);
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());

            if (! is_resource($process)) {
                $this->fail('Could not start an independent payment webhook worker.');
            }

            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        // Release all independent workers together to exercise the database row locks and unique index.
        file_put_contents($workers[0][0], json_encode(['start' => true], JSON_THROW_ON_ERROR));

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $results[] = [
                'exit' => $exit,
                'stdout' => trim($stdout === false ? '' : $stdout),
                'stderr' => trim($stderr === false ? '' : $stderr),
            ];
            $this->assertSame(0, $exit, 'Payment webhook worker failed: '.($stderr === false ? '' : $stderr));
        }

        return $results;
    }
}
