<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\IntegrationSettings;
use App\Services\Payments\PaystackClient;
use App\Services\Security\UserLevelPolicyService;
use App\Services\Wallets\BankPayoutService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use LogicException;
use Mockery;
use Tests\TestCase;

class BankPayoutAdmissionTest extends TestCase
{
    public function test_disabled_payout_provider_blocks_new_payout_before_any_provider_submission(): void
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
