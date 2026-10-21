<?php

namespace Tests\Feature;

use App\Mail\LoginOtpMail;
use App\Models\User;
use App\Services\Security\TotpTwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'auth_security.email_otp_enabled' => false,
            'auth_security.sms_otp_enabled' => false,
            'auth_security.whatsapp_otp_enabled' => false,
            'mail.default' => 'smtp',
        ]);
    }

    public function test_login_requires_totp_or_one_unused_recovery_code_and_recovery_code_is_single_use(): void
    {
        $user = User::query()->create([
            'name' => 'TOTP Login Customer',
            'email' => 'totp-login@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $codes = app(TotpTwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXP');

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/two-factor');
        $this->assertGuest();

        $this->post('/login/two-factor', ['code' => $codes[0]])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/two-factor');

        $this->from('/login/two-factor')->post('/login/two-factor', ['code' => $codes[0]])
            ->assertRedirect('/login/two-factor')
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_fifth_failed_totp_login_attempt_expires_the_pending_challenge(): void
    {
        $user = User::query()->create([
            'name' => 'TOTP Lockout Customer',
            'email' => 'totp-lockout@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        app(TotpTwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXP');

        // Seed four prior failures directly so this regression tests the
        // session challenge limit independently of the shared IP throttle.
        $response = $this->withSession([
            'auth.two_factor_user_id' => (int) $user->id,
            'auth.two_factor_remember' => false,
            'auth.two_factor_attempts' => 4,
        ])->from('/login/two-factor')->post('/login/two-factor', ['code' => '000000']);
        $response->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $response->assertSessionMissing('auth.two_factor_user_id');
        $response->assertSessionMissing('auth.two_factor_attempts');
    }

    public function test_expired_totp_login_challenge_is_rejected_and_cleared(): void
    {
        $user = User::query()->create([
            'name' => 'Expired TOTP Customer',
            'email' => 'totp-expired@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        app(TotpTwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXP');

        $response = $this->withSession([
            'auth.two_factor_user_id' => (int) $user->id,
            'auth.two_factor_remember' => false,
            'auth.two_factor_attempts' => 0,
            'auth.two_factor_started_at' => now()->subMinutes(6)->timestamp,
        ])->from('/login/two-factor')->post('/login/two-factor', ['code' => '000000']);
        $response->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $response->assertSessionMissing('auth.two_factor_user_id');
        $response->assertSessionMissing('auth.two_factor_started_at');
    }

    public function test_email_otp_must_be_completed_before_totp_challenge(): void
    {
        config(['auth_security.email_otp_enabled' => true]);
        config(['auth_security.login_otp_channel' => 'email']);
        config(['auth_security.email_otp_ttl_minutes' => 10]);
        Mail::fake();

        $user = User::query()->create([
            'name' => 'Chained OTP Customer',
            'email' => 'totp-otp-chain@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $codes = app(TotpTwoFactorService::class)->enable($user, 'JBSWY3DPEHPK3PXP');

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/verify');
        $this->assertGuest();

        $emailCode = null;
        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail) use (&$emailCode): bool {
            $emailCode = $mail->code;
            return true;
        });
        $this->assertIsString($emailCode);

        $this->post('/login/verify', ['code' => $emailCode])
            ->assertRedirect('/login/two-factor');
        $this->assertGuest();

        $this->post('/login/two-factor', ['code' => $codes[0]])
            ->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_enable_requires_current_password_and_valid_totp_and_disable_requires_password_and_code(): void
    {
        $user = User::query()->create([
            'name' => 'TOTP Management Customer',
            'email' => 'totp-management@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $secret = 'JBSWY3DPEHPK3PXP';

        $this->actingAs($user)->withSession(['security.totp_setup_secret' => $secret])
            ->post('/security/two-factor/enable', [
                'password' => 'WrongPassword1234',
                'code' => $this->totpCode($secret),
            ])->assertSessionHasErrors('password');
        $this->assertNull($user->fresh()->two_factor_enabled_at);

        $this->actingAs($user)->withSession(['security.totp_setup_secret' => $secret])
            ->post('/security/two-factor/enable', [
                'password' => 'CorrectPassword1234',
                'code' => $this->totpCode($secret),
            ])->assertRedirect('/security/two-factor');
        $this->assertNotNull($user->fresh()->two_factor_enabled_at);
        $this->assertDatabaseCount('user_two_factor_recovery_codes', 10);

        // A valid authenticator code is accepted as the second factor for disablement.
        $this->actingAs($user)->post('/security/two-factor/disable', [
            'password' => 'WrongPassword1234',
            'code' => $this->totpCode($secret),
        ])->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh()->two_factor_enabled_at);

        $this->actingAs($user)->post('/security/two-factor/disable', [
            'password' => 'CorrectPassword1234',
            'code' => $this->totpCode($secret),
        ])->assertRedirect('/security/two-factor');
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_enabled_at);
        $this->assertDatabaseCount('user_two_factor_recovery_codes', 0);
    }

    private function totpCode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }

        $key = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $key .= chr(bindec($chunk));
            }
        }

        $counter = intdiv(now()->timestamp, 30);
        $hash = hash_hmac('sha1', pack('N2', 0, $counter), $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $number = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string) ($number % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
