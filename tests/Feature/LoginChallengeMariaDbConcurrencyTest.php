<?php

namespace Tests\\Feature;

use App\\Models\\User;
use Illuminate\\Foundation\\Testing\\RefreshDatabaseState;
use Illuminate\\Support\\Facades\\Artisan;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Facades\\Hash;
use Illuminate\\Support\\Str;
use Tests\\TestCase;

class LoginChallengeMariaDbConcurrencyTest extends TestCase
{
    private bool $usesDedicatedTestDatabase = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql'
            || ! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('OTP concurrency tests require a dedicated MySQL database whose name ends in _test.');
        }

        if (! function_exists('proc_open')) {
            $this->markTestSkipped('OTP concurrency tests require proc_open.');
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

    public function test_competing_valid_otp_verifications_consume_a_challenge_exactly_once(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent OTP Customer',
            'email' => 'otp-race-'.Str::uuid().'@example.test',
            'password' => Hash::make('test-only-password'),
        ]);
        $challengeId = (string) Str::uuid();
        DB::table('login_challenges')->insert([
            'id' => $challengeId,
            'user_id' => $user->getKey(),
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(5),
            'attempts' => 0,
            'consumed_at' => null,
            'remember' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $worker = base_path('tests/Support/login_challenge_concurrency_worker.php');
        $processes = [];
        foreach ([1, 2] as $_) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $worker, $challengeId, '123456'], [
                0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
            ], $pipes, base_path());
            $this->assertIsResource($process, 'Could not start independent OTP verification worker.');
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);
            $this->assertSame(0, $exit, 'OTP worker failed: '.trim((string) $stderr));
            $results[] = json_decode(trim((string) $stdout), true, flags: JSON_THROW_ON_ERROR);
        }

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['expired', 'ok'], $statuses);
        $this->assertDatabaseHas('login_challenges', [
            'id' => $challengeId,
            'attempts' => 0,
        ]);
        $this->assertNotNull(DB::table('login_challenges')->where('id', $challengeId)->value('consumed_at'));
    }
}
