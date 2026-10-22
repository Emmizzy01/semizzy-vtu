<?php

namespace Tests\Feature;

use App\Mail\LoginOtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LoginChallengeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth_security.email_otp_enabled' => true]);
        config(['auth_security.email_otp_ttl_minutes' => 10]);
        config(['auth_security.email_otp_max_attempts' => 5]);
        config(['mail.default' => 'smtp']);
        Mail::fake();
    }

    public function test_correct_password_requires_email_code_before_authentication(): void
    {
        $user = User::query()->create([
            'name' => 'OTP Customer',
            'email' => 'otp-customer@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/verify');

        $this->assertGuest();
        $this->assertDatabaseHas('login_challenges', [
            'user_id' => $user->id,
            'consumed_at' => null,
            'attempts' => 0,
        ]);
        Mail::assertSent(LoginOtpMail::class);
    }

    public function test_email_code_can_be_used_once_to_complete_login(): void
    {
        $user = User::query()->create([
            'name' => 'OTP Customer',
            'email' => 'otp-single-use@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'CorrectPassword1234'])
            ->assertRedirect('/login/verify');

        $code = null;
        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $this->assertIsString($code);

        $sessionIdBeforeVerification = session()->getId();
        $this->post('/login/verify', ['code' => $code])->assertRedirect('/dashboard');
        $this->assertNotSame($sessionIdBeforeVerification, session()->getId(), 'Completing OTP login must rotate the session identifier.');
        $this->assertAuthenticatedAs($user);
        $challenge = \Illuminate\Support\Facades\DB::table('login_challenges')->where('user_id', $user->id)->first();
        $this->assertNotNull($challenge);
        $this->assertNotNull($challenge->consumed_at);
    }

    public function test_invalid_codes_are_counted_and_attempt_limit_expires_the_challenge(): void
    {
        $user = User::query()->create([
            'name' => 'OTP Customer',
            'email' => 'otp-attempts@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'CorrectPassword1234'])
            ->assertRedirect('/login/verify');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login/verify')->post('/login/verify', ['code' => '000000'])
                ->assertRedirect('/login/verify');
        }

        $this->from('/login/verify')->post('/login/verify', ['code' => '000000'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();
        $this->assertDatabaseHas('login_challenges', [
            'user_id' => $user->id,
            'attempts' => 5,
        ]);
    }

    public function test_expired_code_cannot_authenticate(): void
    {
        $user = User::query()->create([
            'name' => 'OTP Customer',
            'email' => 'otp-expired@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', ['identifier' => $user->email, 'password' => 'CorrectPassword1234'])
            ->assertRedirect('/login/verify');

        $this->travel(11)->minutes();

        $this->post('/login/verify', ['code' => '123456'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('identifier');
        $this->assertGuest();
    }

    public function test_email_otp_fails_closed_when_only_log_mailer_is_configured(): void
    {
        config(['mail.default' => 'log']);
        $user = User::query()->create([
            'name' => 'OTP Customer',
            'email' => 'otp-no-mailer@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->from('/login')->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login')->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertDatabaseCount('login_challenges', 0);
    }

    public function test_account_suspended_after_password_step_cannot_finish_email_otp_login(): void
    {
        $user = User::query()->create([
            'name' => 'Suspended During OTP Customer',
            'email' => 'suspended-during-otp@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/verify');

        $code = null;
        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $this->assertIsString($code);

        $user->forceFill(['account_status' => 'suspended'])->save();

        $this->post('/login/verify', ['code' => $code])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
    }

    public function test_correct_code_is_rejected_after_the_attempt_limit_has_been_reached(): void
    {
        $user = User::query()->create([
            'name' => 'OTP Locked Correct Code Customer',
            'email' => 'otp-locked-correct-code@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/verify');

        $code = null;
        Mail::assertSent(LoginOtpMail::class, function (LoginOtpMail $mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $this->assertIsString($code);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->from('/login/verify')->post('/login/verify', ['code' => '000000'])
                ->assertRedirect('/login/verify');
        }

        $this->from('/login/verify')->post('/login/verify', ['code' => $code])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('identifier');

        $this->assertGuest();
        $this->assertDatabaseHas('login_challenges', [
            'user_id' => $user->id,
            'attempts' => 5,
            'consumed_at' => null,
        ]);
    }


    public function test_new_login_challenge_replaces_the_previous_unconsumed_challenge(): void
    {
        $user = User::query()->create([
            'name' => 'OTP Replacement Customer',
            'email' => 'otp-replacement@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/verify');

        $firstChallengeId = session('auth.login_challenge_id');
        $this->assertIsString($firstChallengeId);
        $this->assertDatabaseHas('login_challenges', ['id' => $firstChallengeId, 'consumed_at' => null]);

        $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ])->assertRedirect('/login/verify');

        $secondChallengeId = session('auth.login_challenge_id');
        $this->assertIsString($secondChallengeId);
        $this->assertNotSame($firstChallengeId, $secondChallengeId);
        $this->assertDatabaseMissing('login_challenges', ['id' => $firstChallengeId]);
        $this->assertDatabaseHas('login_challenges', [
            'id' => $secondChallengeId,
            'user_id' => $user->id,
            'consumed_at' => null,
            'attempts' => 0,
        ]);
        $this->assertGuest();
    }

}
