<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileNumberVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth_security.sms_otp_enabled' => true,
            'services.login_otp.sms.endpoint' => 'https://sms.example.test/otp',
            'services.login_otp.sms.token' => 'test-sms-token',
            'auth_security.email_otp_max_attempts' => 5,
            'auth_security.email_otp_ttl_minutes' => 10,
        ]);
        Http::fake(['https://sms.example.test/otp' => Http::response(['accepted' => true], 200)]);
    }

    public function test_mobile_number_verification_claims_number_and_enables_phone_login(): void
    {
        $user = User::query()->create([
            'name' => 'Mobile Verification Customer',
            'email' => 'mobile-verification@example.test',
            'mobile_number' => '+2348012345678',
            'password' => 'CorrectPassword1234',
        ]);

        $this->actingAs($user)->post('/profile/mobile-number/verification')->assertRedirect();

        $code = $this->sentVerificationCode();
        $this->actingAs($user)->post('/profile/mobile-number/verification/confirm', ['code' => $code])
            ->assertRedirect()->assertSessionHas('status', 'mobile-number-verified');

        $this->assertNotNull($user->fresh()->mobile_verified_at);
        $this->assertDatabaseHas('verified_mobile_numbers', [
            'user_id' => $user->id,
            'mobile_number' => '+2348012345678',
        ]);
        $this->assertNotNull(DB::table('mobile_verification_challenges')->where('user_id', $user->id)->value('consumed_at'));

        $this->post('/logout');
        $this->post('/login', [
            'identifier' => '+234 801 234 5678',
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_verified_mobile_number_cannot_be_claimed_by_a_second_account(): void
    {
        $owner = User::query()->create([
            'name' => 'Mobile Claim Owner',
            'email' => 'mobile-claim-owner@example.test',
            'mobile_number' => '+2348099991111',
            'password' => 'CorrectPassword1234',
        ]);
        $owner->forceFill(['mobile_verified_at' => now()])->save();
        DB::table('verified_mobile_numbers')->insert([
            'user_id' => $owner->id,
            'mobile_number' => '+2348099991111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $other = User::query()->create([
            'name' => 'Mobile Claim Other',
            'email' => 'mobile-claim-other@example.test',
            'mobile_number' => '+2348099991111',
            'password' => 'CorrectPassword1234',
        ]);

        $this->actingAs($other)->post('/profile/mobile-number/verification')->assertRedirect();
        $code = $this->sentVerificationCode();
        $this->actingAs($other)->from('/profile')->post('/profile/mobile-number/verification/confirm', ['code' => $code])
            ->assertRedirect('/profile')->assertSessionHasErrors('mobile_number');

        $this->assertNull($other->fresh()->mobile_verified_at);
        $this->assertDatabaseCount('verified_mobile_numbers', 1);
        $this->assertDatabaseHas('verified_mobile_numbers', ['user_id' => $owner->id, 'mobile_number' => '+2348099991111']);
    }

    public function test_changing_a_mobile_number_clears_verification_and_outstanding_challenges(): void
    {
        $user = User::query()->create([
            'name' => 'Change Mobile Customer',
            'email' => 'change-mobile@example.test',
            'mobile_number' => '+2348077771111',
            'password' => 'CorrectPassword1234',
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();
        DB::table('verified_mobile_numbers')->insert([
            'user_id' => $user->id,
            'mobile_number' => '+2348077771111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('mobile_verification_challenges')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'mobile_number' => '+2348077771111',
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user)->put('/profile', [
            'name' => $user->name,
            'mobile_number' => '+2348077772222',
        ])->assertRedirect();

        $this->assertSame('+2348077772222', $user->fresh()->mobile_number);
        $this->assertNull($user->fresh()->mobile_verified_at);
        $this->assertDatabaseCount('verified_mobile_numbers', 0);
        $this->assertDatabaseCount('mobile_verification_challenges', 0);
    }

    public function test_sms_verification_fails_closed_when_sms_delivery_is_disabled(): void
    {
        config(['auth_security.sms_otp_enabled' => false]);
        $user = User::query()->create([
            'name' => 'SMS Disabled Customer',
            'email' => 'sms-disabled@example.test',
            'mobile_number' => '+2348066661111',
            'password' => 'CorrectPassword1234',
        ]);

        $this->actingAs($user)->post('/profile/mobile-number/verification')
            ->assertRedirect()->assertSessionHasErrors('mobile_number');

        $this->assertDatabaseCount('mobile_verification_challenges', 0);
        Http::assertNothingSent();
    }

    private function sentVerificationCode(): string
    {
        $code = null;
        Http::assertSent(function (ClientRequest $request) use (&$code): bool {
            if ($request->url() !== 'https://sms.example.test/otp'
                || ($request['purpose'] ?? null) !== 'mobile_verification') {
                return false;
            }

            preg_match('/code is ([0-9]{6})/', (string) ($request['message'] ?? ''), $matches);
            $code = $matches[1] ?? null;
            return $code !== null;
        });

        $this->assertIsString($code);
        return $code;
    }
}
