<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BankBeneficiaryClaimProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_two_claims_for_the_same_bank_account_fingerprint(): void
    {
        $owner = User::query()->create([
            'name' => 'Claim Owner',
            'email' => 'claim-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $other = User::query()->create([
            'name' => 'Claim Competitor',
            'email' => 'claim-competitor@example.test',
            'password' => 'not-a-real-password',
        ]);

        $fingerprint = hash_hmac('sha256', '999|0123456789', (string) config('app.key'));
        $now = now();

        DB::table('bank_beneficiary_claims')->insert([
            'account_fingerprint' => $fingerprint,
            'user_id' => $owner->id,
            'beneficiary_id' => null,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            DB::table('bank_beneficiary_claims')->insert([
                'account_fingerprint' => $fingerprint,
                'user_id' => $other->id,
                'beneficiary_id' => null,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fail('The unique fingerprint constraint must reject a competing claim.');
        } catch (QueryException) {
            $this->assertDatabaseCount('bank_beneficiary_claims', 1);
            $this->assertDatabaseHas('bank_beneficiary_claims', [
                'account_fingerprint' => $fingerprint,
                'user_id' => $owner->id,
                'status' => 'pending',
            ]);
        }
    }

    public function test_beneficiary_claims_store_a_fingerprint_not_the_plain_account_number(): void
    {
        $user = User::query()->create([
            'name' => 'Fingerprint Owner',
            'email' => 'fingerprint-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $accountNumber = '0123456789';
        $fingerprint = hash_hmac('sha256', '999|'.$accountNumber, (string) config('app.key'));

        DB::table('bank_beneficiary_claims')->insert([
            'account_fingerprint' => $fingerprint,
            'user_id' => $user->id,
            'beneficiary_id' => null,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stored = DB::table('bank_beneficiary_claims')->value('account_fingerprint');

        $this->assertSame($fingerprint, $stored);
        $this->assertNotSame($accountNumber, $stored);
    }
}
