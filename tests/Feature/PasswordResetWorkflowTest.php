<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_request_uses_a_non_enumerating_response(): void
    {
        Notification::fake();
        $user = User::query()->create(['name' => 'Reset User', 'email' => 'reset-user@example.test', 'password' => 'OriginalPassword123']);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => ' RESET-USER@example.test '])
            ->assertRedirect('/forgot-password')->assertSessionHas('status');
        Notification::assertSentTo($user, ResetPasswordNotification::class);
        $this->from('/forgot-password')->post('/forgot-password', ['email' => 'not-found@example.test'])
            ->assertRedirect('/forgot-password')->assertSessionHas('status');
    }

    public function test_valid_reset_changes_password_and_clears_transaction_pin(): void
    {
        $user = User::query()->create(['name' => 'Reset User', 'email' => 'reset-complete@example.test', 'password' => 'OriginalPassword123']);
        $user->forceFill(['transaction_pin_hash' => Hash::make('492815')])->save();
        $token = Password::createToken($user);
        DB::table('login_challenges')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
            'remember' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ChangedPassword456',
            'password_confirmation' => 'ChangedPassword456',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('ChangedPassword456', $user->fresh()->password));
        $this->assertNull($user->fresh()->transaction_pin_hash);
        $this->assertDatabaseMissing('login_challenges', ['user_id' => $user->id]);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'password_reset_completed',
            'outcome' => 'success',
        ]);
    }

    public function test_successfully_used_reset_token_cannot_be_replayed(): void
    {
        $user = User::query()->create([
            'name' => 'Reset Replay User',
            'email' => 'reset-replay@example.test',
            'password' => 'OriginalPassword123',
        ]);
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ChangedPassword456',
            'password_confirmation' => 'ChangedPassword456',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->from('/reset-password/'.$token)->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ReplayPassword789',
            'password_confirmation' => 'ReplayPassword789',
        ])->assertRedirect('/reset-password/'.$token)->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('ChangedPassword456', $user->fresh()->password));
        $this->assertFalse(Hash::check('ReplayPassword789', $user->fresh()->password));
    }

    public function test_invalid_reset_token_is_rejected_without_changing_password(): void
    {
        $user = User::query()->create(['name' => 'Reset User', 'email' => 'reset-invalid@example.test', 'password' => 'OriginalPassword123']);
        $this->from('/reset-password/fake-token')->post('/reset-password', [
            'token' => 'fake-token',
            'email' => $user->email,
            'password' => 'ChangedPassword456',
            'password_confirmation' => 'ChangedPassword456',
        ])->assertRedirect('/reset-password/fake-token')->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('OriginalPassword123', $user->fresh()->password));
    }
}
