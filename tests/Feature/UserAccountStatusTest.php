<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_suspend_another_user_and_the_change_is_audited(): void
    {
        $admin = User::query()->create(['name' => 'Status Admin', 'email' => 'status-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $customer = User::query()->create(['name' => 'Status Customer', 'email' => 'status-customer@example.test', 'password' => 'not-a-real-password']);

        $this->actingAs($admin)->put('/admin/users/'.$customer->id.'/status', [
            'account_status' => 'suspended', 'reason' => 'Customer account requires review',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'account_status' => 'suspended']);
        $this->assertDatabaseHas('user_account_status_audits', [
            'actor_user_id' => $admin->id, 'target_user_id' => $customer->id,
            'old_status' => 'active', 'new_status' => 'suspended', 'reason' => 'Customer account requires review',
        ]);
    }

    public function test_suspended_account_cannot_sign_in_and_existing_session_is_logged_out(): void
    {
        $user = User::query()->create(['name' => 'Suspended User', 'email' => 'suspended@example.test', 'password' => 'CorrectPassword1234']);
        $user->forceFill(['account_status' => 'suspended'])->save();

        $this->from('/login')->post('/login', ['email' => $user->email, 'password' => 'CorrectPassword1234'])
            ->assertRedirect('/login')->assertSessionHasErrors('identifier');
        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_admin_cannot_suspend_their_own_account(): void
    {
        $admin = User::query()->create(['name' => 'Self Status Admin', 'email' => 'self-status@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->put('/admin/users/'.$admin->id.'/status', [
            'account_status' => 'suspended', 'reason' => 'Trying to suspend own account',
        ])->assertForbidden();
    }

    public function test_suspended_account_is_rejected_across_profile_wallet_and_session_routes(): void
    {
        $user = User::query()->create([
            'name' => 'Suspended Multi Route User',
            'email' => 'suspended-multi-route@example.test',
            'password' => 'CorrectPassword1234',
        ]);
        $user->forceFill(['account_status' => 'suspended'])->save();

        foreach (['/profile', '/wallet/transfers/history', '/account/sessions'] as $uri) {
            $response = $this->actingAs($user)->get($uri);
            $this->assertContains(
                $response->getStatusCode(),
                [302, 303],
                'Suspended account was not redirected on '.$uri.'; received '.$response->getStatusCode().'.'
            );
            $response->assertRedirect('/login')->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

}
