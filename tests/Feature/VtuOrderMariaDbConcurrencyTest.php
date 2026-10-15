<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ManualDeposit;
use App\Models\VtuOrder;
use App\Models\VtuProduct;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class VtuOrderMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        // This suite deliberately uses committed rows shared by independent PHP
        // processes. Never run its schema reset against SQLite or a non-test DB.
        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('MariaDB concurrency tests require a dedicated MySQL database whose name ends in _test.');
        }

        $this->usesDedicatedTestDatabase = true;
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::purge('mysql');

        config([
            'services.vtu.name' => 'concurrency-test-provider',
            'services.vtu.endpoint' => 'https://provider.example.test/api',
            'services.vtu.token' => 'test-only-token',
            'services.vtu.driver' => 'configured',
        ]);
    }

    protected function tearDown(): void
    {
        // The independent workers commit their rows, so restore a clean schema
        // before Laravel's transactional RefreshDatabase tests run afterwards.
        if ($this->usesDedicatedTestDatabase) {
            Artisan::call('migrate:fresh', ['--force' => true]);
            DB::purge('mysql');
            RefreshDatabaseState::$migrated = false;
        }

        parent::tearDown();
    }

    public function test_two_independent_staff_workers_approve_a_manual_deposit_and_credit_once(): void
    {
        $customer = User::query()->create([
            'name' => 'Concurrent Deposit Customer',
            'email' => 'deposit-customer-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);
        $admin = User::query()->create([
            'name' => 'Concurrent Deposit Admin',
            'email' => 'deposit-admin-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        $deposit = ManualDeposit::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $customer->getKey(),
            'wallet_id' => $wallet->getKey(),
            'amount_minor' => 12500,
            'transfer_reference' => 'CONCURRENT-'.Str::uuid(),
            'evidence_path' => 'manual-deposits/concurrency-test.pdf',
            'evidence_sha256' => hash('sha256', 'concurrency-evidence-'.Str::uuid()),
            'status' => 'pending',
            'idempotency_key' => 'manual-deposit:'.Str::uuid(),
            'evidence_viewed_at' => now(),
            'evidence_viewed_by_user_id' => $admin->getKey(),
        ]);

        $state = $this->newStateFile();
        $results = $this->runWorkers([
            ['review-deposit', $state, (string) $admin->getKey(), $deposit->public_id],
            ['review-deposit', $state, (string) $admin->getKey(), $deposit->public_id],
        ], assertSuccessful: false);

        $this->assertSame([0, 1], collect(array_column($results, 'exit'))->sort()->values()->all(),
            'Exactly one worker should approve the pending deposit; the competing review should conflict.');
        $this->assertSame('approved', $deposit->fresh()->status);
        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1, DB::table('wallet_ledger_entries')->where('reference', $deposit->public_id)->count());
        $this->assertSame(1, DB::table('wallet_transactions')->where('reference', $deposit->public_id)->count());
        $this->assertSame(1, DB::table('manual_deposit_audits')->where('manual_deposit_id', $deposit->getKey())->where('event_type', 'approve')->count());

        @unlink($state);
    }

    public function test_two_independent_workers_open_only_one_first_wallet_for_a_user(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent Wallet Creation Customer',
            'email' => 'wallet-race-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);
        $state = $this->newStateFile();

        $results = $this->runWorkers([
            ['open-wallet', $state, (string) $user->getKey()],
            ['open-wallet', $state, (string) $user->getKey()],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $this->assertSame($results[0]['stdout'], $results[1]['stdout'], 'Both workers must return the same wallet ID.');
        $this->assertSame(1, DB::table('wallets')->where('user_id', $user->getKey())->where('currency', 'NGN')->count());
        $this->assertSame(0, (int) DB::table('wallets')->where('user_id', $user->getKey())->value('balance_minor'));
        $this->assertSame(0, DB::table('wallet_transactions')->count());

        @unlink($state);
    }

    public function test_two_independent_purchase_workers_create_one_order_debit_and_provider_submission(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $state = $this->newStateFile();

        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'parallel-purchase-once'],
            ['purchase', $state, (string) $user->getKey(), $product->code, 'parallel-purchase-once'],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Funding plus exactly one purchase debit are expected.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);

        $order = VtuOrder::query()->sole();
        $this->assertSame('processing', $order->status);
        $this->assertSame(1, $this->readState($state, 'submissions'));
        $this->assertSame(0, $this->readState($state, 'lookups'));

        @unlink($state);
    }

    public function test_competing_purchase_workers_cannot_overdraw_one_wallet(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1000);
        $state = $this->newStateFile();

        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'competing-purchase-a'],
            ['purchase', $state, (string) $user->getKey(), $product->code, 'competing-purchase-b'],
        ], assertSuccessful: false);

        $this->assertSame([0, 1], collect(array_column($results, 'exit'))->sort()->values()->all());
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Funding plus only one purchase debit are expected.');
        $this->assertSame(0, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(1, $this->readState($state, 'submissions'));
        $this->assertSame(0, $this->readState($state, 'lookups'));

        @unlink($state);
    }

    public function test_two_reconciliation_workers_refund_a_failed_order_once(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'parallel-reconcile-once',
            'request_hash' => hash('sha256', 'parallel-reconcile-once'),
            'service_type' => $product->service_type,
            'network' => $product->network,
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => $product->price_minor,
            'currency' => 'NGN',
            'status' => 'processing',
            'provider' => 'concurrency-test-provider',
            'dispatch_started_at' => now()->subMinutes(10),
        ]);

        $debit = app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'debit',
            amountMinor: (int) $product->price_minor,
            idempotencyKey: 'vtu-purchase:'.$order->public_id,
            reference: $order->public_id,
            description: 'Concurrency test purchase debit',
            actorUserId: (int) $user->getKey(),
            metadata: ['vtu_order_id' => $order->public_id],
            correlationId: $order->public_id,
        );
        $order->forceFill(['wallet_transaction_id' => $debit->getKey()])->save();

        $state = $this->newStateFile();
        $results = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
            ['reconcile', $state, (string) $order->getKey()],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $this->assertSame('failed', $order->fresh()->status);
        $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertSame(3, DB::table('wallet_transactions')->count(), 'Funding, one debit, and exactly one refund are expected.');
        $this->assertSame(2, $this->readState($state, 'lookups'));
        $this->assertSame(0, $this->readState($state, 'submissions'));

        @unlink($state);
    }

    public function test_concurrent_mixed_provider_outcomes_cannot_reverse_the_first_terminal_result(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'mixed-provider-outcome',
            'request_hash' => hash('sha256', 'mixed-provider-outcome'),
            'service_type' => $product->service_type,
            'network' => $product->network,
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => $product->price_minor,
            'currency' => 'NGN',
            'status' => 'processing',
            'provider' => 'concurrency-test-provider',
            'dispatch_started_at' => now()->subMinutes(10),
        ]);

        $debit = app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'debit',
            amountMinor: (int) $product->price_minor,
            idempotencyKey: 'vtu-purchase:'.$order->public_id,
            reference: $order->public_id,
            description: 'Concurrency test purchase debit',
            actorUserId: (int) $user->getKey(),
            metadata: ['vtu_order_id' => $order->public_id],
            correlationId: $order->public_id,
        );
        $order->forceFill(['wallet_transaction_id' => $debit->getKey()])->save();

        $state = $this->newStateFile(['mixed_outcomes' => 1]);
        $results = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
            ['reconcile', $state, (string) $order->getKey()],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $finalStatus = $order->fresh()->status;
        $this->assertContains($finalStatus, ['successful', 'failed']);
        $this->assertSame(2, $this->readState($state, 'lookups'));
        $this->assertSame(0, $this->readState($state, 'submissions'));
        $this->assertDatabaseCount('vtu_orders', 1);

        if ($finalStatus === 'successful') {
            $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertSame(2, DB::table('wallet_transactions')->count(), 'A successful terminal result must not be refunded.');
        } else {
            $this->assertSame(1500, (int) $wallet->fresh()->available_balance_minor);
            $this->assertSame(3, DB::table('wallet_transactions')->count(), 'A failed terminal result must be refunded exactly once.');
        }

        @unlink($state);
    }

    public function test_concurrent_success_provider_results_commit_one_terminal_success(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'success-success-provider-race',
            'request_hash' => hash('sha256', 'success-success-provider-race'),
            'service_type' => $product->service_type,
            'network' => $product->network,
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => $product->price_minor,
            'currency' => 'NGN',
            'status' => 'processing',
            'provider' => 'concurrency-test-provider',
            'dispatch_started_at' => now()->subMinutes(10),
        ]);

        $debit = app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'debit',
            amountMinor: (int) $product->price_minor,
            idempotencyKey: 'vtu-purchase:'.$order->public_id,
            reference: $order->public_id,
            description: 'Concurrency test purchase debit',
            actorUserId: (int) $user->getKey(),
            metadata: ['vtu_order_id' => $order->public_id],
            correlationId: $order->public_id,
        );
        $order->forceFill(['wallet_transaction_id' => $debit->getKey()])->save();

        $state = $this->newStateFile(['success_success' => 1]);
        $results = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
            ['reconcile', $state, (string) $order->getKey()],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $this->assertSame('successful', $order->fresh()->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Concurrent successes must not add another debit or a refund.');
        $this->assertSame(2, $this->readState($state, 'lookups'));
        $this->assertSame(0, $this->readState($state, 'submissions'));

        @unlink($state);
    }

    public function test_concurrent_pending_provider_result_cannot_regress_success(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $order = VtuOrder::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->getKey(),
            'wallet_id' => $wallet->getKey(),
            'vtu_product_id' => $product->getKey(),
            'idempotency_key' => 'success-pending-provider-race',
            'request_hash' => hash('sha256', 'success-pending-provider-race'),
            'service_type' => $product->service_type,
            'network' => $product->network,
            'product_code' => $product->code,
            'recipient' => '08012345678',
            'amount_minor' => $product->price_minor,
            'currency' => 'NGN',
            'status' => 'processing',
            'provider' => 'concurrency-test-provider',
            'dispatch_started_at' => now()->subMinutes(10),
        ]);

        $debit = app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'debit',
            amountMinor: (int) $product->price_minor,
            idempotencyKey: 'vtu-purchase:'.$order->public_id,
            reference: $order->public_id,
            description: 'Concurrency test purchase debit',
            actorUserId: (int) $user->getKey(),
            metadata: ['vtu_order_id' => $order->public_id],
            correlationId: $order->public_id,
        );
        $order->forceFill(['wallet_transaction_id' => $debit->getKey()])->save();

        $state = $this->newStateFile(['success_pending' => 1]);
        $results = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
            ['reconcile', $state, (string) $order->getKey()],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        $this->assertSame('successful', $order->fresh()->status);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'A pending response must never trigger a refund or another debit.');
        $this->assertSame(2, $this->readState($state, 'lookups'));
        $this->assertSame(0, $this->readState($state, 'submissions'));

        @unlink($state);
    }

    public function test_crash_before_provider_receives_request_keeps_order_ambiguous_without_refund_or_resubmission(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $state = $this->newStateFile([
            'crash_before_submit' => 1,
            'single_lookup' => 1,
        ]);

        // The order and debit commit before submit(). The fake provider exits
        // before recording any outbound request, modelling a crash at the
        // dispatch boundary before the provider receives the purchase.
        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'crash-before-provider-receives'],
            ['purchase', $state, (string) $user->getKey(), $product->code, 'crash-before-provider-receives'],
        ], assertSuccessful: false);

        $this->assertSame([0, 87], collect(array_column($results, 'exit'))->sort()->values()->all());
        $this->assertDatabaseCount('vtu_orders', 1);
        $order = VtuOrder::query()->sole();
        $this->assertSame('processing', $order->status);
        $this->assertNotNull($order->dispatch_started_at);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Funding and one purchase debit must persist.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame(0, $this->readState($state, 'submissions'), 'The provider fake must confirm no request was received.');

        $recovery = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
        ]);
        $this->assertSame([0], array_column($recovery, 'exit'));
        $this->assertSame('awaiting_reconciliation', $order->fresh()->status);
        $this->assertSame(0, $this->readState($state, 'submissions'), 'Recovery must not blindly resubmit.');
        $this->assertSame(1, $this->readState($state, 'lookups'));
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor, 'An unresolved provider lookup must not refund the debit.');
        $this->assertSame(2, DB::table('wallet_transactions')->count());

        @unlink($state);
    }

    public function test_crashed_worker_after_provider_acceptance_recovers_by_lookup_without_resubmission(): void
    {
        [$user, $wallet, $product] = $this->preparedCustomer(1000, 1500);
        $state = $this->newStateFile([
            'crash_after_submit' => 1,
            'single_lookup' => 1,
        ]);

        // Two requests meet at the purchase barrier. Exactly one submits to the
        // fake provider and exits abruptly before persisting its response; the
        // competing idempotent request must reuse the same order.
        $results = $this->runWorkers([
            ['purchase', $state, (string) $user->getKey(), $product->code, 'crash-after-provider-acceptance'],
            ['purchase', $state, (string) $user->getKey(), $product->code, 'crash-after-provider-acceptance'],
        ], assertSuccessful: false);

        $this->assertSame([0, 86], collect(array_column($results, 'exit'))->sort()->values()->all());
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Funding and one purchase debit must persist across the crash.');
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertSame('processing', VtuOrder::query()->sole()->status);
        $this->assertSame(1, $this->readState($state, 'submissions'));
        $this->assertSame(0, $this->readState($state, 'lookups'));

        $order = VtuOrder::query()->sole();
        $recovery = $this->runWorkers([
            ['reconcile', $state, (string) $order->getKey()],
        ]);

        $this->assertSame([0], array_column($recovery, 'exit'));
        $this->assertSame('successful', $order->fresh()->status);
        $this->assertSame('concurrency-provider-'.$order->public_id, $order->fresh()->provider_reference);
        $this->assertSame(500, (int) $wallet->fresh()->available_balance_minor);
        $this->assertDatabaseCount('vtu_orders', 1);
        $this->assertSame(2, DB::table('wallet_transactions')->count(), 'Recovery must not debit or refund again.');
        $this->assertSame(1, $this->readState($state, 'submissions'), 'Recovery must never resubmit a purchase.');
        $this->assertSame(1, $this->readState($state, 'lookups'), 'Recovery must query provider state once.');

        @unlink($state);
    }

    private function preparedCustomer(int $priceMinor, int $initialBalanceMinor): array
    {
        $user = User::query()->create([
            'name' => 'MariaDB Concurrency Customer',
            'email' => 'concurrency-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post(
            walletId: (int) $wallet->getKey(),
            type: 'credit',
            amountMinor: $initialBalanceMinor,
            idempotencyKey: 'test-funding:'.$user->getKey(),
            reference: 'test-funding:'.$user->getKey(),
            description: 'Test-only funding',
        );

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'test_network',
            'code' => 'concurrency-airtime-'.Str::uuid(),
            'name' => 'Concurrency Test Airtime',
            'price_minor' => $priceMinor,
            'face_value_minor' => $priceMinor,
            'currency' => 'NGN',
            'is_active' => true,
        ]);

        return [$user, $wallet->fresh(), $product];
    }

    private function newStateFile(array $initialState = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'semizzy-vtu-race-');
        if ($path === false) {
            $this->fail('Could not create shared concurrency state file.');
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
        $script = base_path('tests/Support/vtu_concurrency_worker.php');

        foreach ($workers as $arguments) {
            $command = array_merge([PHP_BINARY, $script], $arguments);
            $pipes = [];
            $process = proc_open($command, [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ], $pipes, base_path());

            if (! is_resource($process)) {
                $this->fail('Could not start an independent MariaDB concurrency worker.');
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
                $this->assertSame(0, $result['exit'], 'Worker failed: '.$result['stderr']);
            }
        }

        return $results;
    }

    private function readState(string $path, string $key): int
    {
        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? (int) ($state[$key] ?? 0) : 0;
    }
}
