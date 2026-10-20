<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsureRecentPasskeyConfirmation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasskeySecurityFoundationTest extends TestCase
{
    use RefreshDatabase;
    public function test_passkey_routes_are_disabled_by_default(): void
    {
        config(['passkeys.enabled' => false]);
        Route::middleware(EnsurePasskeysEnabled::class)->get('/__test/passkeys-disabled', fn () => response('ok'));

        $this->get('/__test/passkeys-disabled')->assertNotFound();
    }

    public function test_enabled_passkeys_reject_non_https_origins(): void
    {
        config([
            'passkeys.enabled' => true,
            'passkeys.relying_party_id' => 'semizzy.example.test',
            'passkeys.allowed_origins' => ['http://semizzy.example.test'],
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        ]);
        Route::middleware(EnsurePasskeysEnabled::class)->get('/__test/passkeys-http-origin', fn () => response('ok'));

        $this->get('/__test/passkeys-http-origin')->assertStatus(503);
    }

    public function test_passkey_management_requires_recent_password_confirmation(): void
    {
        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get('/__test/passkeys-confirm', fn () => response('ok'));

        $this->get('/__test/passkeys-confirm')->assertForbidden();
        $this->withSession(['passkeys.password_confirmed_at' => now()->timestamp])
            ->get('/__test/passkeys-confirm')->assertOk();
    }
}
