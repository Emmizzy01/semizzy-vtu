<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
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

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ChangedPassword456',
            'password_confirmation' => 'ChangedPassword456',
        ])->assertRedirect('/login')->assertSessionHas('status');

        $this->assertTrue(Hash::check('ChangedPassword456', $user->fresh()->password));
        $this->assertNull($user->fresh()->transaction_pin_hash);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'password_reset_completed',
            'outcome' => 'success',
        ]);
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
