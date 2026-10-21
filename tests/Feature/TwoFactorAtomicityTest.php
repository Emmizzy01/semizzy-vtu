<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Security\TotpTwoFactorService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabling_two_factor_atomically_stores_encrypted_secret_and_ten_hashed_recovery_codes(): void
    {
        $user = User::query()->create([
            'name' => 'Two Factor Customer',
            'email' => 'two-factor-enable@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $codes = app(TotpTwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXP');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh->two_factor_enabled_at);
        $this->assertNotSame('JBSWY3DPEHPK3PXP', $fresh->two_factor_secret);
        $this->assertSame('JBSWY3DPEHPK3PXP', Crypt::decryptString($fresh->two_factor_secret));
        $this->assertCount(10, $codes);
        $this->assertDatabaseCount('user_two_factor_recovery_codes', 10);

        foreach ($codes as $code) {
            $this->assertDatabaseMissing('user_two_factor_recovery_codes', [
                'user_id' => $user->id,
                'code_hash' => $code,
            ]);
        }

        $records = DB::table('user_two_factor_recovery_codes')->where('user_id', $user->id)->get();
        foreach ($records as $record) {
            $this->assertTrue(
                collect($codes)->contains(fn (string $code): bool => Hash::check($code, $record->code_hash)),
                'Each persisted recovery code must be represented by one of the one-time plaintext codes returned to the user.'
            );
        }
    }

    public function test_disabling_two_factor_clears_secret_state_and_recovery_codes_together(): void
    {
        $user = User::query()->create([
            'name' => 'Two Factor Disable Customer',
            'email' => 'two-factor-disable@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $totp = app(TotpTwoFactorService::class);
        $totp->enable($user, 'JBSWY3DPEHPK3PXP');
        $this->assertDatabaseCount('user_two_factor_recovery_codes', 10);

        $totp->disable($user);

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_enabled_at);
        $this->assertDatabaseCount('user_two_factor_recovery_codes', 0);
    }

    public function test_failed_recovery_code_insert_rolls_back_two_factor_enablement_and_prior_codes(): void
    {
        $user = User::query()->create([
            'name' => 'Two Factor Rollback Customer',
            'email' => 'two-factor-rollback@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        DB::listen(function (QueryExecuted $query) use ($user): void {
            if (
                str_contains(strtolower($query->sql), 'user_two_factor_recovery_codes')
                && str_starts_with(strtolower(ltrim($query->sql)), 'insert')
                && in_array($user->id, $query->bindings, true)
            ) {
                throw new \RuntimeException('Injected recovery-code persistence failure.');
            }
        });

        try {
            app(TotpTwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXP');
            $this->fail('Expected recovery-code persistence failure was not raised.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected recovery-code persistence failure.', $exception->getMessage());
        }

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_enabled_at);
        $this->assertDatabaseCount('user_two_factor_recovery_codes', 0);
    }
}
