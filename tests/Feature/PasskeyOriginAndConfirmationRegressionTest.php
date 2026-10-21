<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsureRecentPasskeyConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasskeyOriginAndConfirmationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_enabled_passkeys_reject_wildcard_origins(): void
    {
        config([
            'passkeys.enabled' => true,
            'passkeys.relying_party_id' => 'semizzy.example.test',
            'passkeys.allowed_origins' => ['https://*.semizzy.example.test'],
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);

        Route::middleware(EnsurePasskeysEnabled::class)->get(
            '/__test/passkeys-wildcard-origin-regression',
            fn () => response('ok')
        );

        $this->get('/__test/passkeys-wildcard-origin-regression')->assertStatus(503);
    }

    public function test_passkey_management_rejects_expired_password_confirmation(): void
    {
        $user = User::query()->create(['name' => 'Expired Confirmation', 'email' => 'expired-confirmation@example.test', 'password' => 'CorrectPassword1234']);
        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get(
            '/__test/passkeys-expired-confirmation-regression',
            fn () => response('ok')
        );

        $this->actingAs($user)->withSession([
            'passkeys.password_confirmed_at' => now()->subMinutes(6)->timestamp,
            'passkeys.password_confirmed_password_hash' => hash('sha256', (string) $user->password),
        ])->get('/__test/passkeys-expired-confirmation-regression')->assertForbidden();
    }

    public function test_passkey_management_accepts_confirmation_within_five_minutes_for_the_same_password(): void
    {
        $user = User::query()->create(['name' => 'Recent Confirmation', 'email' => 'recent-confirmation@example.test', 'password' => 'CorrectPassword1234']);
        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get(
            '/__test/passkeys-recent-confirmation-regression',
            fn () => response('ok')
        );

        $this->actingAs($user)->withSession([
            'passkeys.password_confirmed_at' => now()->subMinutes(4)->timestamp,
            'passkeys.password_confirmed_password_hash' => hash('sha256', (string) $user->password),
        ])->get('/__test/passkeys-recent-confirmation-regression')->assertOk();
    }

    public function test_password_change_invalidates_an_otherwise_recent_passkey_confirmation(): void
    {
        $user = User::query()->create(['name' => 'Changed Password', 'email' => 'changed-password-confirmation@example.test', 'password' => 'CorrectPassword1234']);
        $oldPasswordHash = hash('sha256', (string) $user->password);
        $user->forceFill(['password' => 'ChangedPassword5678'])->save();

        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get(
            '/__test/passkeys-password-changed-regression',
            fn () => response('ok')
        );

        $this->actingAs($user)->withSession([
            'passkeys.password_confirmed_at' => now()->timestamp,
            'passkeys.password_confirmed_password_hash' => $oldPasswordHash,
        ])->get('/__test/passkeys-password-changed-regression')->assertForbidden();
    }
}
