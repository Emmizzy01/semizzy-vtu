<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->actingAs($user)->get('/account/sessions')->assertOk();
        $record = DB::table('user_sessions')->where('user_id', $user->id)->first();
        DB::table('user_sessions')->where('id', $record->id)->update(['revoked_at' => now()]);
        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_change_revokes_other_sessions_and_preserves_the_current_login(): void
    {
        $user = User::query()->create(['name' => 'Session Customer', 'email' => 'sessions-password@example.test', 'password' => 'CorrectPassword1234']);
        $this->actingAs($user)->get('/account/sessions')->assertOk();
        $current = DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->first();
        $this->assertNotNull($current);
        $otherHash = hash('sha256', 'another-device-session');
        DB::table('user_sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $user->id, 'session_hash' => $otherHash,
            'ip_address' => '192.0.2.10', 'user_agent' => 'Test other device', 'last_activity' => now(),
            'revoked_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->put('/profile/password', [
            'current_password' => 'CorrectPassword1234', 'password' => 'DifferentPassword5678',
            'password_confirmation' => 'DifferentPassword5678',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(DB::table('user_sessions')->where('session_hash', $otherHash)->value('revoked_at'));
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
}
