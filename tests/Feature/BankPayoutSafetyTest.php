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

    public function test_http_payout_endpoint_rejects_wrong_pin_before_creating_payout(): void
    {
        $user = User::query()->create([
            'name' => 'PIN Protected Payout User',
            'email' => 'pin-protected-payout@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill([
            'transaction_pin_hash' => \Illuminate\Support\Facades\Hash::make('4928'),
        ])->save();

        $this->actingAs($user)->postJson('/wallet/bank-transfer', [
            'beneficiary_id' => 999,
            'amount_minor' => 25000,
            'transaction_pin' => '1111',
            'idempotency_key' => 'payout-wrong-pin-test-001',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Transaction PIN is incorrect.');

        $this->assertDatabaseCount('bank_payouts', 0);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'pin_verification',
            'outcome' => 'failed',
        ]);
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
    public function test_user_can_remove_own_saved_bank_account_when_it_has_no_transfer_history(): void
    {
        $user = User::query()->create([
            'name' => 'Account Owner',
            'email' => 'account-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Example Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => 'Account Owner',
            'provider_recipient_code' => 'RCP_remove_test_123',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        app(BankPayoutService::class)->removeBeneficiary($user, $beneficiary->id);

        $this->assertDatabaseMissing('bank_beneficiaries', ['id' => $beneficiary->id]);
    }

    public function test_saved_bank_account_with_payout_history_cannot_be_removed(): void
    {
        $user = User::query()->create([
            'name' => 'History Owner',
            'email' => 'history-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '998',
            'bank_name' => 'History Test Bank',
            'account_number' => '9876543210',
            'account_number_last4' => '3210',
            'account_name' => 'History Owner',
            'provider_recipient_code' => 'RCP_history_test_123',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        \App\Models\BankPayout::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'bank_beneficiary_id' => $beneficiary->id,
            'currency' => 'NGN',
            'amount_minor' => 10000,
            'fee_minor' => 0,
            'status' => 'provider_pending',
            'idempotency_key' => 'bank-history-test-001',
            'request_hash' => hash('sha256', 'test'),
            'provider_reference' => 'semizzy_history_test_001',
            'wallet_transaction_id' => null,
            'metadata' => ['provider' => 'test'],
        ]);

        try {
            app(BankPayoutService::class)->removeBeneficiary($user, $beneficiary->id);
            $this->fail('An account referenced by payout history must be retained.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('transfer history', $exception->getMessage());
        }

        $this->assertDatabaseHas('bank_beneficiaries', ['id' => $beneficiary->id]);
    }


    public function test_payout_history_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::query()->create(['name' => 'Payout Owner', 'email' => 'payout-owner-scope@example.test', 'password' => 'not-a-real-password']);
        $other = User::query()->create(['name' => 'Other Payout User', 'email' => 'payout-other-scope@example.test', 'password' => 'not-a-real-password']);
        $makePayout = function (User $user, string $suffix): void {
            $beneficiary = \\App\\Models\\BankBeneficiary::query()->create([
                'user_id' => $user->id, 'bank_code' => '999', 'bank_name' => 'Scope Test Bank',
                'account_number' => '0123456789', 'account_number_last4' => '6789',
                'account_name' => $user->name, 'provider_recipient_code' => 'RCP_scope_'.$suffix,
                'verification_status' => 'verified', 'verified_at' => now(),
            ]);
            \\App\\Models\\BankPayout::query()->create([
                'public_id' => (string) \\Illuminate\\Support\\Str::uuid(), 'user_id' => $user->id,
                'bank_beneficiary_id' => $beneficiary->id, 'currency' => 'NGN', 'amount_minor' => 10000,
                'fee_minor' => 0, 'status' => 'provider_pending', 'idempotency_key' => 'payout-scope-'.$suffix,
                'request_hash' => hash('sha256', $suffix), 'provider_reference' => 'semizzy_scope_'.$suffix,
                'wallet_transaction_id' => null, 'metadata' => ['provider' => 'test'],
            ]);
        };
        $makePayout($owner, 'owner');
        $makePayout($other, 'other');

        $this->actingAs($owner)->getJson('/wallet/bank-transfer/history')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount_minor', 10000);
        $this->actingAs($other)->getJson('/wallet/bank-transfer/history')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('bank_payouts', 2);
    }
}
