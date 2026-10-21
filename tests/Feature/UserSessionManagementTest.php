<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_requests_register_a_hashed_session_identifier(): void
    {
        $user = User::query()->create(['name' => 'Session Customer', 'email' => 'sessions@example.test', 'password' => 'CorrectPassword1234']);
        $this->actingAs($user)->get('/account/sessions')->assertOk();
        $record = DB::table('user_sessions')->where('user_id', $user->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(64, strlen($record->session_hash));
        $this->assertNull($record->revoked_at);
    }

    public function test_revoked_session_is_logged_out_on_its_next_authenticated_request(): void
    {
        $user = User::query()->create(['name' => 'Session Customer', 'email' => 'sessions-revoke@example.test', 'password' => 'CorrectPassword1234']);

        // Use a persistent test session handler so session IDs survive separate
        // HTTP requests, matching the file/database handlers used in deployment.
        config(['session.driver' => 'file']);
        // The test environment defaults to the array driver; Laravel may already
        // have resolved its session store before this per-test override. Purge
        // both cached layers so every request uses the persistent file handler.
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        // Exercise a real login so the test client carries the regenerated session
        // identifier in its cookie across requests, as a browser does.
        $loginResponse = $this->post('/login', [
            'identifier' => $user->email,
            'password' => 'CorrectPassword1234',
        ]);
        $loginResponse->assertRedirect('/dashboard');

        // Laravel's HTTP test client does not automatically replay response
        // cookies like a browser. Replay the session cookie from login so every
        // subsequent request uses the same server-side session.
        // getCookie() decrypts Laravel's response cookie by default. Feed that
        // plaintext session ID to withCookie(), which builds the incoming test
        // request cookie; replaying the raw encrypted Set-Cookie value here can
        // make the next request use a different session ID.
        $sessionCookie = $loginResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($sessionCookie);
        $this->withCookie(config('session.cookie'), $sessionCookie->getValue());

        // Each HTTP request must authenticate from the replayed cookie, not a cached guard user.
        Auth::forgetGuards();
        $this->get('/account/sessions')->assertOk();
        $record = DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->orderByDesc('last_activity')->orderByDesc('updated_at')->first();
        $this->assertNotNull($record);
        // Revoke the session most recently observed by the authenticated request,
        // rather than an arbitrary older device record for the same account.
        DB::table('user_sessions')->where('id', $record->id)->update(['revoked_at' => now()]);

        // Clear the in-memory guard again so this request proves revocation is enforced
        // from the session cookie and server-side session record.
        Auth::forgetGuards();
        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_change_revokes_other_sessions_and_preserves_the_current_login(): void
    {
        $user = User::query()->create(['name' => 'Session Customer', 'email' => 'sessions-password@example.test', 'password' => 'CorrectPassword1234']);
        $this->actingAs($user)->get('/account/sessions')->assertOk();
        $current = DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->first();
        $this->assertNotNull($current);
        DB::table('login_challenges')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'code_hash' => bcrypt('123456'),
            'expires_at' => now()->addMinutes(10),
            'attempts' => 0,
            'consumed_at' => null,
            'remember' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherHash = hash('sha256', 'another-device-session');
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'session_hash' => $otherHash,
            'ip_address' => '192.0.2.10', 'user_agent' => 'Test other device', 'last_activity' => now(),
            'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $passwordChangeResponse = $this->withSession([
            'passkeys.password_confirmed_at' => now()->timestamp,
            'passkeys.password_confirmed_password_hash' => hash('sha256', (string) $user->password),
        ])->put('/profile/password', [
            'current_password' => 'CorrectPassword1234', 'password' => 'DifferentPassword5678',
            'password_confirmation' => 'DifferentPassword5678',
        ]);
        $passwordChangeResponse->assertRedirect();
        $passwordChangeResponse->assertSessionMissing('passkeys.password_confirmed_at');
        $passwordChangeResponse->assertSessionMissing('passkeys.password_confirmed_password_hash');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(DB::table('user_sessions')->where('session_hash', $otherHash)->value('revoked_at'));
        $this->assertDatabaseMissing('login_challenges', ['user_id' => $user->id]);
        $this->assertNotNull(DB::table('user_sessions')->where('id', $current->id)->value('revoked_at'));
        $this->assertSame(1, DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_password_reset_session_revocation_service_marks_all_active_sessions(): void
    {
        $user = User::query()->create(['name' => 'Session Customer', 'email' => 'sessions-reset@example.test', 'password' => 'CorrectPassword1234']);
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'session_hash' => hash('sha256', 'old-device-session'),
            'ip_address' => null, 'user_agent' => 'Test device', 'last_activity' => now(), 'revoked_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(\App\Services\Security\UserSessionService::class)->revokeAll($user);
        $this->assertNotNull(DB::table('user_sessions')->where('user_id', $user->id)->value('revoked_at'));
    }

    public function test_user_cannot_revoke_another_users_session(): void
    {
        $owner = User::query()->create(['name' => 'Session Owner', 'email' => 'session-owner@example.test', 'password' => 'CorrectPassword1234']);
        $other = User::query()->create(['name' => 'Other Customer', 'email' => 'other-session-owner@example.test', 'password' => 'CorrectPassword1234']);
        $otherSessionId = (string) Str::uuid();
        DB::table('user_sessions')->insert([
            'id' => $otherSessionId, 'user_id' => $other->id, 'session_hash' => hash('sha256', 'other-users-private-session'),
            'ip_address' => '192.0.2.20', 'user_agent' => 'Private device', 'last_activity' => now(),
            'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($owner)->delete('/account/sessions/'.$otherSessionId)
            ->assertRedirect()->assertSessionHasErrors('session');

        $this->assertNull(DB::table('user_sessions')->where('id', $otherSessionId)->value('revoked_at'));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_revoking_current_session_logs_the_user_out(): void
    {
        $user = User::query()->create(['name' => 'Current Session Customer', 'email' => 'current-session@example.test', 'password' => 'CorrectPassword1234']);
        config(['session.driver' => 'file']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        $loginResponse = $this->post('/login', ['identifier' => $user->email, 'password' => 'CorrectPassword1234']);
        $loginResponse->assertRedirect('/dashboard');
        $cookie = $loginResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie(config('session.cookie'), $cookie->getValue());
        Auth::forgetGuards();

        $this->get('/account/sessions')->assertOk();
        $current = DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->orderByDesc('last_activity')->orderByDesc('updated_at')->first();
        $this->assertNotNull($current);

        Auth::forgetGuards();
        $this->delete('/account/sessions/'.$current->id)->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotNull(DB::table('user_sessions')->where('id', $current->id)->value('revoked_at'));
    }

    public function test_revoke_others_preserves_the_current_session_and_revokes_other_devices(): void
    {
        $user = User::query()->create(['name' => 'Revoke Other Devices Customer', 'email' => 'revoke-other-devices@example.test', 'password' => 'CorrectPassword1234']);
        config(['session.driver' => 'file']);
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        $loginResponse = $this->post('/login', ['identifier' => $user->email, 'password' => 'CorrectPassword1234']);
        $loginResponse->assertRedirect('/dashboard');
        $cookie = $loginResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->withCookie(config('session.cookie'), $cookie->getValue());
        Auth::forgetGuards();

        $this->get('/account/sessions')->assertOk();
        $current = DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->orderByDesc('last_activity')->orderByDesc('updated_at')->first();
        $this->assertNotNull($current);
        $otherId = (string) Str::uuid();
        DB::table('user_sessions')->insert([
            'id' => $otherId, 'user_id' => $user->id, 'session_hash' => hash('sha256', 'another-active-device'),
            'ip_address' => '192.0.2.30', 'user_agent' => 'Another device', 'last_activity' => now(),
            'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Auth::forgetGuards();
        $this->post('/account/sessions/revoke-others')->assertRedirect();
        $this->assertNull(DB::table('user_sessions')->where('id', $current->id)->value('revoked_at'));
        $this->assertNotNull(DB::table('user_sessions')->where('id', $otherId)->value('revoked_at'));
        $this->assertAuthenticatedAs($user);
    }

}
