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

    public function test_failed_read_only_account_resolution_releases_claim_for_safe_retry(): void
    {
        $user = User::query()->create([
            'name' => 'Resolution Retry Owner',
            'email' => 'resolution-retry-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('listNigerianBanks')->once()->andReturn([
            ['name' => 'Example Test Bank', 'code' => '999'],
        ]);
        $paystack->shouldReceive('resolveNigerianAccount')
            ->once()
            ->with('0123456789', '999')
            ->andThrow(new \RuntimeException('Account resolution failed before recipient creation.'));
        $paystack->shouldNotReceive('createTransferRecipient');
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        try {
            app(\App\Services\Wallets\BankPayoutService::class)
                ->addBeneficiary($user, '999', '0123456789');
            $this->fail('A failed account resolution must be reported.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Account resolution failed before recipient creation.', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_beneficiary_claims', 0);
        $this->assertDatabaseCount('bank_beneficiaries', 0);
    }

    public function test_unknown_recipient_creation_outcome_keeps_claim_and_blocks_provider_retry(): void
    {
        $user = User::query()->create([
            'name' => 'Unknown Recipient Owner',
            'email' => 'unknown-recipient-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $paystack = \\Mockery::mock(\\App\\Services\\Payments\\PaystackClient::class);
        $paystack->shouldReceive('listNigerianBanks')->twice()->andReturn([
            ['name' => 'Example Test Bank', 'code' => '999'],
        ]);
        $paystack->shouldReceive('resolveNigerianAccount')
            ->once()
            ->with('0123456789', '999')
            ->andReturn(['account_name' => 'Unknown Recipient Owner', 'account_number' => '0123456789']);
        $paystack->shouldReceive('createTransferRecipient')
            ->once()
            ->with('Unknown Recipient Owner', '0123456789', '999')
            ->andThrow(new \\LogicException('Bank recipient registration result is unknown; verify provider state before retrying.'));
        $this->app->instance(\\App\\Services\\Payments\\PaystackClient::class, $paystack);

        try {
            app(\\App\\Services\\Wallets\\BankPayoutService::class)
                ->addBeneficiary($user, '999', '0123456789');
            $this->fail('An unknown recipient result must not be treated as a confirmed success.');
        } catch (\\LogicException $exception) {
            $this->assertStringContainsString('result is unknown', $exception->getMessage());
        }

        $fingerprint = hash_hmac('sha256', '999|0123456789', (string) config('app.key'));
        $this->assertDatabaseHas('bank_beneficiary_claims', [
            'account_fingerprint' => $fingerprint,
            'user_id' => $user->id,
            'beneficiary_id' => null,
            'status' => 'pending',
        ]);

        try {
            app(\\App\\Services\\Wallets\\BankPayoutService::class)
                ->addBeneficiary($user, '999', '0123456789');
            $this->fail('A retry must be blocked while the provider creation outcome is unknown.');
        } catch (\\LogicException $exception) {
            $this->assertStringContainsString('already being verified', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_beneficiaries', 0);
        $this->assertDatabaseCount('bank_beneficiary_claims', 1);
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
