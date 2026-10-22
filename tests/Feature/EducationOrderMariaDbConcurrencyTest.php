<?php

namespace Tests\Feature;

use App\Models\EducationOrder;
use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\PlatformAddon;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class EducationOrderMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Independent PHP processes share committed rows. Never reset a non-test DB.
        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('Education concurrency tests require a dedicated MySQL database whose name ends in _test.');
        }

        $this->usesDedicatedTestDatabase = true;
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::purge('mysql');

        PlatformFeatureControl::query()->updateOrCreate(
            ['feature_key' => 'service.education'],
            [
                'label' => 'Education services',
                'category' => 'services',
                'owner' => 'platform',
                'description' => 'Test-only education concurrency feature gate',
                'default_enabled' => true,
                'is_enabled' => true,
                'is_core' => false,
                'is_implemented' => true,
            ],
        );
        PlatformAddon::query()->updateOrCreate(
            ['addon_key' => 'service.education'],
            [
                'name' => 'Education test addon',
                'version' => '1.0.0',
                'minimum_core_version' => '1.0.0',
                'status' => 'enabled',
                'is_core_owned' => false,
                'compatibility_status' => 'compatible',
            ],
        );
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

    public function test_competing_duplicate_purchases_reserve_and_debit_only_once(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer();
        $state = $this->newStateFile();

        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'education-concurrent-same-key'],
            ['purchase', $state, (string) $user->getKey(), $product->code, 'education-concurrent-same-key'],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame(1, DB::table('education_orders')->count());
        $this->assertSame(1, DB::table('education_provider_attempts')->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('type', 'debit')->count());
        $this->assertSame(2, DB::table('wallet_ledger_entries')->count(), 'One opening credit and one education debit are expected.');
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1, $this->readState($state, 'submissions'), 'Only the order creator may submit.');
        @unlink($state);
    }

    public function test_crash_after_reservation_before_provider_submission_keeps_debit_and_does_not_retry(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer();
        $state = $this->newStateFile(['crash_before_submit' => 1]);

        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'education-crash-before-submit'],
        ], assertSuccessful: false);

        $this->assertSame([87], array_column($results, 'exit'));
        $order = EducationOrder::query()->sole();
        $this->assertSame('processing', $order->status);
        $this->assertNotNull($order->wallet_transaction_id);
        $this->assertSame(0, $this->readState($state, 'submissions'));
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(2, DB::table('wallet_ledger_entries')->count());

        // An idempotent duplicate must reuse the durable order, not submit again.
        $retry = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'education-crash-before-submit'],
        ]);
        $this->assertSame([0], array_column($retry, 'exit'));
        $this->assertSame(0, $this->readState($state, 'submissions'));
        $this->assertSame(1, DB::table('education_orders')->count());
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        @unlink($state);
    }

    public function test_crash_after_provider_acceptance_recovers_by_lookup_without_resubmission(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer();
        $state = $this->newStateFile(['crash_after_acceptance' => 1]);

        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'education-crash-after-acceptance'],
        ], assertSuccessful: false);

        $this->assertSame([86], array_column($results, 'exit'));
        $order = EducationOrder::query()->sole();
        $this->assertSame('processing', $order->status);
        $this->assertSame(1, $this->readState($state, 'submissions'));
        $this->assertSame(0, $this->readState($state, 'lookups'));

        $this->mergeState($state, ['lookup_status' => 'success']);
        $recovery = $this->runWorkers([['reconcile', $state, (string) $order->getKey()]]);

        $this->assertSame([0], array_column($recovery, 'exit'));
        $this->assertSame('successful', $order->fresh()->status);
        $this->assertSame(1, $this->readState($state, 'submissions'), 'Recovery must never submit the purchase again.');
        $this->assertSame(1, $this->readState($state, 'lookups'));
        $this->assertSame(350000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(2, DB::table('wallet_ledger_entries')->count(), 'Success must not create a second debit or a refund.');
        @unlink($state);
    }

    public function test_competing_reconciliation_workers_apply_definitive_refund_once(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer();
        $state = $this->newStateFile(['lookup_status' => 'failed']);
        $purchase = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'education-reconcile-failure'],
        ]);
        $this->assertSame([0], array_column($purchase, 'exit'));
        $order = EducationOrder::query()->sole();
        $this->assertSame('unknown', $order->status);
        $this->assertSame(1, $this->readState($state, 'submissions'));

        $this->mergeState($state, ['lookup_status' => 'failed', 'lookup_barrier' => 1]);
        $results = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
            ['reconcile', $state, (string) $order->getKey()],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(500000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1, DB::table('wallet_transactions')->where('idempotency_key', 'education-refund:'.$order->public_id)->count());
        $this->assertSame(3, DB::table('wallet_ledger_entries')->count(), 'Opening credit, one purchase debit, and one compensation credit are expected.');
        $this->assertSame(1, $this->readState($state, 'submissions'));
        $this->assertSame(2, $this->readState($state, 'lookups'));
        @unlink($state);
    }

    private function preparedCustomer(): array
    {
        $user = User::query()->create([
            'name' => 'Education MariaDB Concurrency Customer',
            'email' => 'education-concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
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
            amountMinor: 500000,
            idempotencyKey: 'education-test-funding:'.$user->getKey(),
            reference: 'education-test-funding:'.$user->getKey(),
            description: 'Education concurrency test opening balance',
            actorUserId: (int) $user->getKey(),
        );

        $product = EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin',
            'exam_body' => 'WAEC',
            'name' => 'Education MariaDB Concurrency Product',
            'code' => 'education-concurrency-'.Str::uuid(),
            'provider' => 'education-concurrency-test',
            'provider_product_code' => 'test-waec-pin',
            'price_minor' => 150000,
            'currency' => 'NGN',
            'requires_candidate_validation' => true,
            'is_active' => true,
            'purchase_approved' => true,
        ]);

        return [$user, $wallet->fresh(), $product];
    }

    private function newStateFile(array $initialState = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'semizzy-education-race-');
        if ($path === false) {
            $this->fail('Could not create education concurrency state file.');
        }
        file_put_contents($path, json_encode($initialState, JSON_THROW_ON_ERROR));
        return $path;
    }

    /** @param list<list<string>> $workers
     *  @return list<array{exit:int,stdout:string,stderr:string}>
     */
    private function runWorkers(array $workers, bool $assertSuccessful = true): array
    {
        $processes = [];
        $script = base_path('tests/Support/education_concurrency_worker.php');

        foreach ($workers as $arguments) {
            $command = array_merge([PHP_BINARY, $script], $arguments);
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());

            if (! is_resource($process)) {
                $this->fail('Could not start an independent education MariaDB worker.');
            }
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
                'stdout' => trim($stdout === false ? '' : $stdout),
                'stderr' => trim($stderr === false ? '' : $stderr),
            ];
        }

        if ($assertSuccessful) {
            foreach ($results as $result) {
                $this->assertSame(0, $result['exit'], 'Education worker failed: '.$result['stderr']);
            }
        }

        return $results;
    }

    private function mergeState(string $path, array $updates): void
    {
        $state = json_decode((string) file_get_contents($path), true);
        $state = is_array($state) ? $state : [];
        file_put_contents($path, json_encode(array_merge($state, $updates), JSON_THROW_ON_ERROR));
    }

    private function readState(string $path, string $key): int
    {
        $state = json_decode((string) file_get_contents($path), true);
        return is_array($state) ? (int) ($state[$key] ?? 0) : 0;
    }
}
