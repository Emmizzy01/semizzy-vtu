<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallets\BankPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BankPayoutSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_payouts_are_disabled_by_default_and_do_not_debit_wallet(): void
    {
        $user = User::query()->create([
            'name' => 'Bank Payout User',
            'email' => 'bank-payout-user@example.test',
            'password' => 'not-a-real-password',
        ]);

        try {
            app(BankPayoutService::class)->requestPayout($user, 999, 25000, 'bank-payout-disabled-001');
            $this->fail('Payout should be blocked until the administrator enables provider payouts.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled by the administrator', $exception->getMessage());
        }

        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('bank_payouts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_bank_beneficiary_account_number_is_encrypted_at_rest(): void
    {
        $user = User::query()->create([
            'name' => 'Beneficiary Owner',
            'email' => 'beneficiary-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Example Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => 'Beneficiary Owner',
            'provider_recipient_code' => 'RCP_test_123456',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $stored = \Illuminate\Support\Facades\DB::table('bank_beneficiaries')->where('id', $beneficiary->id)->value('account_number');
        $this->assertNotSame('0123456789', $stored);
        $this->assertSame('0123456789', $beneficiary->fresh()->account_number);
    }
}
