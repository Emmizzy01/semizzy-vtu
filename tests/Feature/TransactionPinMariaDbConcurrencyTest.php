<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionPinMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('PIN concurrency tests require a dedicated MySQL database whose name ends in _test.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('PIN concurrency tests require proc_open.');
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

    public function test_competing_pin_changes_cannot_both_replace_the_same_current_pin(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent PIN Change Customer',
            'email' => 'pin-race-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);
        $user->forceFill([
            'transaction_pin_hash' => Hash::make('4928'),
            'transaction_pin_failed_attempts' => 0,
            'transaction_pin_locked_until' => null,
        ])->save();

        $gate = tempnam(sys_get_temp_dir(), 'semizzy-pin-race-');
        if ($gate === false) {
            $this->fail('Could not create PIN concurrency gate file.');
        }
        file_put_contents($gate, 'wait');

        $worker = base_path('tests/Support/transaction_pin_concurrency_worker.php');
        $processes = [];
        foreach (['7359', '8642'] as $newPin) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, $worker, 'change', $gate, (string) $user->getKey(), '4928', $newPin],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                base_path(),
            );
            $this->assertIsResource($process, 'Could not start independent PIN-change worker.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        file_put_contents($gate, 'go');

        $results = [];
        try {
            foreach ($processes as [$process, $pipes]) {
                $stdout = stream_get_contents($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                $this->assertSame(0, $exit, 'Independent PIN worker failed: '.trim((string) $stderr));
                $decoded = json_decode(trim((string) $stdout), true);
                $this->assertIsArray($decoded, 'PIN worker did not return structured output.');
                $results[] = $decoded['result'] ?? null;
            }

            sort($results);
            $this->assertSame(['changed', 'invalid'], $results);

            $fresh = $user->fresh();
            $this->assertTrue(
                Hash::check('7359', (string) $fresh->transaction_pin_hash)
                    || Hash::check('8642', (string) $fresh->transaction_pin_hash),
            );
            $this->assertSame(1, (int) $fresh->transaction_pin_failed_attempts);
            $this->assertSame(1, DB::table('transaction_security_events')
                ->where('user_id', $user->getKey())
                ->where('event_type', 'pin_changed')
                ->where('outcome', 'success')
                ->count());
            $this->assertSame(1, DB::table('transaction_security_events')
                ->where('user_id', $user->getKey())
                ->where('event_type', 'pin_verification')
                ->where('outcome', 'failed')
                ->count());
        } finally {
            @unlink($gate);
        }
    }
}
