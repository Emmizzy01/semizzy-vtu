<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsureRecentPasskeyConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasskeySecurityFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_passkey_management_lists_only_credentials_owned_by_the_authenticated_user(): void
    {
        config(['passkeys.enabled' => true]);

        $owner = User::query()->create([
            'name' => 'Passkey Owner',
            'email' => 'passkey-owner@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $other = User::query()->create([
            'name' => 'Other Passkey Owner',
            'email' => 'other-passkey-owner@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $ownerCredentialId = \\Illuminate\\Support\\Facades\\DB::table('passkeys')->insertGetId([
            'user_id' => $owner->id,
            'name' => 'Owner device',
            'credential_id' => 'owner-credential-test',
            'credential' => json_encode(['test' => 'owner'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \\Illuminate\\Support\\Facades\\DB::table('passkeys')->insert([
            'user_id' => $other->id,
            'name' => 'Other device',
            'credential_id' => 'other-credential-test',
            'credential' => json_encode(['test' => 'other'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($owner)->get('/security/passkeys')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Security/Passkeys')
                ->has('credentials', 1)
                ->where('credentials.0.id', $ownerCredentialId)
                ->where('credentials.0.name', 'Owner device'));
    }

    public function test_user_cannot_delete_another_users_passkey_credential(): void
    {
        config([
            'passkeys.enabled' => true,
            'passkeys.relying_party_id' => 'semizzy.example.test',
            'passkeys.allowed_origins' => ['https://semizzy.example.test'],
        ]);

        $owner = User::query()->create([
            'name' => 'Credential Owner',
            'email' => 'credential-owner@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $attacker = User::query()->create([
            'name' => 'Credential Attacker',
            'email' => 'credential-attacker@example.test',
            'password' => 'CorrectPassword1234',
        ]);

        $credentialId = \\Illuminate\\Support\\Facades\\DB::table('passkeys')->insertGetId([
            'user_id' => $owner->id,
            'name' => 'Owner credential',
            'credential_id' => 'owner-delete-protection-test',
            'credential' => json_encode(['test' => 'credential'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($attacker)->withSession([
            'passkeys.password_confirmed_at' => now()->timestamp,
            'passkeys.password_confirmed_password_hash' => hash('sha256', (string) $attacker->password),
        ])->delete('/user/passkeys/'.$credentialId)->assertForbidden();

        $this->assertDatabaseHas('passkeys', [
            'id' => $credentialId,
            'user_id' => $owner->id,
            'credential_id' => 'owner-delete-protection-test',
        ]);
    }

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

    public function test_passkey_management_requires_recent_password_confirmation_bound_to_authenticated_user(): void
    {
        $user = User::query()->create(['name' => 'Passkey Confirmation', 'email' => 'passkey-confirmation@example.test', 'password' => 'CorrectPassword1234']);
        Route::middleware(['web', EnsureRecentPasskeyConfirmation::class])->get('/__test/passkeys-confirm', fn () => response('ok'));

        $this->get('/__test/passkeys-confirm')->assertForbidden();
        $this->actingAs($user)->withSession([
            'passkeys.password_confirmed_at' => now()->timestamp,
            'passkeys.password_confirmed_password_hash' => hash('sha256', (string) $user->password),
        ])->get('/__test/passkeys-confirm')->assertOk();
    }
}
