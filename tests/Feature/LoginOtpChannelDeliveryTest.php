<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LoginOtpChannelDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_login_otp_uses_configured_https_adapter_and_keeps_user_guest(): void
    {
        config([
            'auth_security.login_otp_channel' => 'sms',
            'auth_security.sms_otp_enabled' => true,
            'services.login_otp.sms.endpoint' => 'https://sms.example.test/messages',
            'services.login_otp.sms.token' => 'test-token',
        ]);
        Http::fake(['sms.example.test/*' => Http::response(['accepted' => true], 202)]);

        $user = User::query()->create([
            'name' => 'SMS OTP Customer',
            'email' => 'sms-otp@example.test',
            'mobile_number' => '+2348012345678',
            'password' => 'CorrectPassword1234',
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();
        DB::table('verified_mobile_numbers')->insert([
            'user_id' => $user->id,
            'mobile_number' => '+2348012345678',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'CorrectPassword1234'])
            ->assertRedirect('/login/verify');

        $this->assertGuest();
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://sms.example.test/messages'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request['to'] === '+2348012345678'
            && $request['purpose'] === 'login_otp'
            && $request['channel'] === 'sms'
            && str_contains($request['message'], 'SEMIZZY sign-in code')
        );
    }

    public function test_sms_login_otp_refuses_an_unverified_mobile_destination(): void
    {
        config([
            'auth_security.login_otp_channel' => 'sms',
            'auth_security.sms_otp_enabled' => true,
            'services.login_otp.sms.endpoint' => 'https://sms.example.test/messages',
            'services.login_otp.sms.token' => 'test-token',
        ]);
        Http::fake(['sms.example.test/*' => Http::response(['accepted' => true], 202)]);

        $user = User::query()->create([
            'name' => 'Unverified SMS OTP Customer',
            'email' => 'unverified-sms-otp@example.test',
            'mobile_number' => '+2348012345678',
            'password' => 'CorrectPassword1234',
        ]);

        $this->from('/login')->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertDatabaseCount('login_challenges', 0);
        Http::assertNothingSent();
    }

    public function test_whatsapp_login_otp_requires_verified_whatsapp_destination(): void
    {
        config([
            'auth_security.login_otp_channel' => 'whatsapp',
            'auth_security.whatsapp_otp_enabled' => true,
            'services.login_otp.whatsapp.endpoint' => 'https://whatsapp.example.test/messages',
            'services.login_otp.whatsapp.token' => 'test-token',
        ]);
        Http::fake(['whatsapp.example.test/*' => Http::response(['accepted' => true], 202)]);

        $user = User::query()->create([
            'name' => 'WhatsApp OTP Customer',
            'email' => 'whatsapp-otp@example.test',
            'whatsapp_number' => '+2348098765432',
            'whatsapp_verified_at' => now(),
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', ['identifier' => '+2348098765432', 'password' => 'CorrectPassword1234'])
            ->assertRedirect('/login/verify');

        $this->assertGuest();
        Http::assertSent(fn ($request) =>
            $request->url() === 'https://whatsapp.example.test/messages'
            && $request['to'] === '+2348098765432'
            && $request['channel'] === 'whatsapp'
        );
    }

    public function test_phone_otp_fails_closed_when_provider_is_not_configured(): void
    {
        config([
            'auth_security.login_otp_channel' => 'sms',
            'auth_security.sms_otp_enabled' => true,
            'services.login_otp.sms.endpoint' => '',
            'services.login_otp.sms.token' => '',
        ]);

        $user = User::query()->create([
            'name' => 'SMS Missing Provider',
            'email' => 'sms-no-provider@example.test',
            'mobile_number' => '+2348012345678',
            'password' => 'CorrectPassword1234',
        ]);

        $this->from('/login')->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertDatabaseCount('login_challenges', 0);
    }
    public function test_sms_provider_rejection_fails_closed_without_leaving_a_usable_challenge(): void
    {
        config([
            'auth_security.login_otp_channel' => 'sms',
            'auth_security.sms_otp_enabled' => true,
            'services.login_otp.sms.endpoint' => 'https://sms.example.test/messages',
            'services.login_otp.sms.token' => 'test-token',
        ]);
        Http::fake(['sms.example.test/*' => Http::response(['accepted' => false], 503)]);

        $user = User::query()->create([
            'name' => 'SMS Provider Rejection',
            'email' => 'sms-provider-rejection@example.test',
            'mobile_number' => '+2348012345678',
            'password' => 'CorrectPassword1234',
        ]);
        $user->forceFill(['mobile_verified_at' => now()])->save();
        DB::table('verified_mobile_numbers')->insert([
            'user_id' => $user->id,
            'mobile_number' => '+2348012345678',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->from('/login')->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertDatabaseCount('login_challenges', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://sms.example.test/messages');
    }

    public function test_whatsapp_provider_rejection_fails_closed_without_fallback_to_authentication(): void
    {
        config([
            'auth_security.login_otp_channel' => 'whatsapp',
            'auth_security.whatsapp_otp_enabled' => true,
            'services.login_otp.whatsapp.endpoint' => 'https://whatsapp.example.test/messages',
            'services.login_otp.whatsapp.token' => 'test-token',
        ]);
        Http::fake(['whatsapp.example.test/*' => Http::response(['accepted' => false], 502)]);

        $user = User::query()->create([
            'name' => 'WhatsApp Provider Rejection',
            'email' => 'whatsapp-provider-rejection@example.test',
            'whatsapp_number' => '+2348098765432',
            'whatsapp_verified_at' => now(),
            'password' => 'CorrectPassword1234',
        ]);

        $this->from('/login')->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertDatabaseCount('login_challenges', 0);
        Http::assertSent(fn ($request) => $request->url() === 'https://whatsapp.example.test/messages');
    }

}
