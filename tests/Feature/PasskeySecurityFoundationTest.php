<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsureRecentPasskeyConfirmation;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasskeySecurityFoundationTest extends TestCase
{
    public function test_passkey_routes_are_disabled_by_default(): void
    {
        config(['passkeys.enabled' => false]);
        Route::middleware(EnsurePasskeysEnabled::class)->get('/__test/passkeys-disabled', fn () => response('ok'));

        $this->get('/__test/passkeys-disabled')->assertNotFound();
    }

    public function test_passkey_management_requires_recent_password_confirmation(): void
    {
        Route::middleware(EnsureRecentPasskeyConfirmation::class)->get('/__test/passkeys-confirm', fn () => response('ok'));

        $this->get('/__test/passkeys-confirm')->assertForbidden();
        $this->withSession(['passkeys.password_confirmed_at' => now()->timestamp])
            ->get('/__test/passkeys-confirm')->assertOk();
    }
}
