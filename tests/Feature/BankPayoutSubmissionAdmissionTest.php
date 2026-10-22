<?php

namespace Tests\Feature;

use App\Models\BankBeneficiary;
use App\Models\BankPayout;
use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\Payments\PaystackClient;
use App\Services\Security\UserLevelPolicyService;
use App\Services\Wallets\BankPayoutService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Mockery;
use Tests\TestCase;

class BankPayoutSubmissionAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_disabled_after_payout_creation_blocks_provider_submission_without_refund_or_duplicate_debit(): void
    {
        $user = User::query()->create([
            'name' => 'Payout Admission Customer',
            'email' => 'payout-admission@example.test',
            'password' => 'test-password',
        ]);

        $wallet = app(WalletAccountService::class)->openForUser($user, 'NGN');
        app(WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 10000,
            idempotencyKey: 'payout-admission-test-funding',
            reference: 'payout-admission-test-funding',
            description: 'Test funding',
            actorUserId: $user->id,
            isFinancialRecovery: true,
        );

        $beneficiary = BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '058',
            'bank_name' => 'Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => 'Payout Admission Customer',
            'provider_recipient_code' => 'RCP_test_admission',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        // The first admission check passes. The administrator disables transfers
        // before the durable payout reaches the external-submission boundary.
        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')
            ->with('paystack.transfers_enabled', '0')
            ->twice()
            ->andReturn('1', '0');
        $this->app->instance(IntegrationSettings::class, $settings);

        $paystack = Mockery::mock(PaystackClient::class);
        $paystack->shouldReceive('mode')->once()->andReturn('test');
        $paystack->shouldNotReceive('initiateBankTransfer');
        $this->app->instance(PaystackClient::class, $paystack);

        $tierPolicy = Mockery::mock(UserLevelPolicyService::class);
        $tierPolicy->shouldReceive('rejectionReasonForAmount')->once()->andReturn(null);
        $this->app->instance(UserLevelPolicyService::class, $tierPolicy);

        $payout = app(BankPayoutService::class)->requestPayout(
            $user,
            (int) $beneficiary->id,
            2500,
            'payout-admission-race-001',
        );

        $this->assertSame('reconciliation_required', $payout->status);
        $this->assertSame('Provider outcome is not confirmed. Do not retry automatically.', $payout->failure_reason);
        $this->assertSame(1, BankPayout::query()->where('user_id', $user->id)->count());

        // The debit is durable for manual reconciliation; the failed admission
        // must neither refund it automatically nor create a second debit.
        $this->assertSame(7500, (int) $wallet->fresh()->balance_minor);
        $this->assertSame(1, $wallet->transactions()->where('type', 'debit')->count());

    }
}
