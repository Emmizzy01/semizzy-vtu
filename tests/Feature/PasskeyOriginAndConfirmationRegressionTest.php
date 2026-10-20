<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsureRecentPasskeyConfirmation;
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
        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get(
            '/__test/passkeys-expired-confirmation-regression',
            fn () => response('ok')
        );

        $this->withSession([
            'passkeys.password_confirmed_at' => now()->subMinutes(6)->timestamp,
        ])->get('/__test/passkeys-expired-confirmation-regression')->assertForbidden();
    }

    public function test_passkey_management_accepts_confirmation_within_five_minutes(): void
    {
        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get(
            '/__test/passkeys-recent-confirmation-regression',
            fn () => response('ok')
        );

        $this->withSession([
            'passkeys.password_confirmed_at' => now()->subMinutes(4)->timestamp,
        ])->get('/__test/passkeys-recent-confirmation-regression')->assertOk();
    }
}
