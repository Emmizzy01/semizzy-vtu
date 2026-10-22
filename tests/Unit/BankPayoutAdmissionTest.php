<?php

namespace Tests\Feature;

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

class BankPayoutAdmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_disabled_payout_provider_blocks_new_recipient_side_effect_before_claiming_account(): void
    {
        $paystack = Mockery::mock(PaystackClient::class);
        $paystack->shouldReceive('listNigerianBanks')
            ->once()
            ->andReturn([['name' => 'Test Bank', 'code' => '001']]);
        $paystack->shouldNotReceive('resolveNigerianAccount');
        $paystack->shouldNotReceive('createTransferRecipient');

        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')
            ->once()
            ->with('paystack.transfers_enabled', '0')
            ->andReturn('0');

        $service = new BankPayoutService(
            $paystack,
            $settings,
            Mockery::mock(WalletAccountService::class),
            Mockery::mock(WalletLedgerService::class),
            Mockery::mock(UserLevelPolicyService::class),
        );

        try {
            $service->addBeneficiary(new User(['id' => 999]), '001', '0123456789');
            $this->fail('A disabled payout provider must reject new provider-recipient creation.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_beneficiary_claims', 0);
        $this->assertDatabaseCount('bank_beneficiaries', 0);
    }

    public function test_disabled_payout_provider_blocks_new_payout_before_transfer_submission(): void
    {
        $paystack = Mockery::mock(PaystackClient::class);
        $paystack->shouldNotReceive('initiateBankTransfer');

        $settings = Mockery::mock(IntegrationSettings::class);
        $settings->shouldReceive('get')
            ->once()
            ->with('paystack.transfers_enabled', '0')
            ->andReturn('0');

        $service = new BankPayoutService(
            $paystack,
            $settings,
            Mockery::mock(WalletAccountService::class),
            Mockery::mock(WalletLedgerService::class),
            Mockery::mock(UserLevelPolicyService::class),
        );

        try {
            $service->requestPayout(new User(), 123, 10000, 'payout-admission-001');
            $this->fail('A disabled payout provider must reject a new payout.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }
    }
}
