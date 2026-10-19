<?php

namespace Tests\Feature;

use App\Models\ReferralAttribution;
use App\Models\User;
use App\Services\Referrals\ReferralProgramService;
use App\Services\Wallets\WalletAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ReferralProgramTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'not-a-real-password',
        ]);
    }

    public function test_referral_code_attributes_new_user_once_and_never_self_refers(): void
    {
        $referrer = $this->makeUser('Referrer', 'referrer@example.test');
        $referred = $this->makeUser('Referred', 'referred@example.test');
        $service = app(ReferralProgramService::class);
        $service->updateSettings(true, 50000, 'admin_verified', $referrer, 'Enable controlled referral rewards');

        $profile = $service->ensureProfile($referrer);
        $this->assertSame(10, strlen($profile->code));

        $service->attributeNewUser($referred, $profile->code);
        $service->attributeNewUser($referred, $profile->code);
        $service->attributeNewUser($referrer, $profile->code);

        $this->assertDatabaseCount('referral_attributions', 1);
        $this->assertDatabaseHas('referral_attributions', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
            'status' => 'pending',
        ]);
    }

    public function test_reward_is_credited_through_ledger_once_after_admin_review(): void
    {
        $referrer = $this->makeUser('Referrer', 'reward-referrer@example.test');
        $referred = $this->makeUser('Referred', 'reward-referred@example.test');
        $admin = $this->makeUser('Reviewer', 'reviewer@example.test');
        $service = app(ReferralProgramService::class);
        $service->updateSettings(true, 50000, 'admin_verified', $admin, 'Configure referral reward for test');
        $profile = $service->ensureProfile($referrer);
        $service->attributeNewUser($referred, $profile->code);
        $attribution = ReferralAttribution::query()->where('referred_user_id', $referred->id)->firstOrFail();

        $reviewed = $service->review($attribution->id, 'qualify', 'VERIFIED-ORDER-001', 'Verified qualifying order in admin review', $admin);

        $wallet = app(WalletAccountService::class)->openForUser($referrer);
        $this->assertSame('rewarded', $reviewed->status);
        $this->assertSame(50000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'idempotency_key' => 'referral:'.$attribution->id.':reward',
            'type' => 'credit',
            'amount_minor' => 50000,
            'status' => 'posted',
        ]);

        try {
            $service->review($attribution->id, 'qualify', 'VERIFIED-ORDER-001', 'Attempt duplicate reward', $admin);
            $this->fail('Finalized referral should not be rewarded twice.');
        } catch (LogicException) {
            $this->assertSame(50000, (int) $wallet->fresh()->balance_minor);
            $this->assertDatabaseCount('wallet_transactions', 1);
        }
    }
}
