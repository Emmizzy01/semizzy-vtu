<?php

namespace Tests\Feature;

use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceMember;
use App\Models\User;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BusinessWorkspaceTeamManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): User
    {
        return User::query()->create([
            'name' => 'Team Test User',
            'email' => $email,
            'password' => 'CorrectPassword1234',
        ]);
    }

    private function workspace(User $owner, string $slug = 'team-workspace'): BusinessWorkspace
    {
        return BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Team Workspace',
            'slug' => $slug,
            'type' => 'business',
            'status' => 'active',
        ]);
    }

    public function test_owner_can_invite_existing_user_and_invitation_acceptance_is_bound_to_invited_account(): void
    {
        Notification::fake();
        $owner = $this->account('team-owner@example.test');
        $invitee = $this->account('team-invitee@example.test');
        $workspace = $this->workspace($owner);

        $this->actingAs($owner)->post(route('workspaces.team.invite', $workspace), [
            'email' => $invitee->email,
            'role' => 'agent',
            'permissions' => ['orders.view'],
        ])->assertRedirect();

        $membership = BusinessWorkspaceMember::query()
            ->where('business_workspace_id', $workspace->id)
            ->where('user_id', $invitee->id)
            ->firstOrFail();

        $this->assertSame('invited', $membership->status);
        $this->assertNotEmpty($membership->invite_token_hash);
        $this->assertNotNull($membership->invite_expires_at);
        Notification::assertSentTo($invitee, WorkspaceInvitationNotification::class);

        $sent = null;
        Notification::assertSentTo($invitee, WorkspaceInvitationNotification::class, function (WorkspaceInvitationNotification $notification) use (&$sent): bool {
            $sent = $notification;
            return true;
        });
        $path = (string) parse_url($sent->acceptUrl, PHP_URL_PATH);
        $token = basename($path);

        $this->actingAs($owner)->get(route('workspaces.invitations.show', ['membership' => $membership->id, 'token' => $token]))
            ->assertForbidden();

        $this->actingAs($invitee)->get(route('workspaces.invitations.show', ['membership' => $membership->id, 'token' => $token]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Workspaces/AcceptInvitation')->where('workspace.name', $workspace->name));

        $this->actingAs($invitee)->post(route('workspaces.invitations.accept', ['membership' => $membership->id, 'token' => $token]))
            ->assertRedirect(route('workspaces.index'));

        $membership->refresh();
        $this->assertSame('active', $membership->status);
        $this->assertNotNull($membership->accepted_at);
        $this->assertNull($membership->invite_token_hash);
        $this->assertNull($membership->invite_expires_at);
        $this->assertTrue($workspace->fresh()->hasActiveMember($invitee));

        $this->actingAs($invitee)->post(route('workspaces.invitations.accept', ['membership' => $membership->id, 'token' => $token]))
            ->assertNotFound();
    }

    public function test_expired_or_modified_invitation_token_cannot_be_accepted(): void
    {
        $owner = $this->account('team-expiry-owner@example.test');
        $invitee = $this->account('team-expiry-invitee@example.test');
        $workspace = $this->workspace($owner, 'team-expiry-workspace');
        $token = 'valid-secret-token';
        $membership = BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'role' => 'agent',
            'permissions' => ['orders.view'],
            'status' => 'invited',
            'invite_token_hash' => hash('sha256', $token),
            'invite_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($invitee)->get(route('workspaces.invitations.show', ['membership' => $membership->id, 'token' => $token]))
            ->assertNotFound();
        $this->actingAs($invitee)->post(route('workspaces.invitations.accept', ['membership' => $membership->id, 'token' => 'wrong-token']))
            ->assertNotFound();
        $this->assertSame('invited', $membership->fresh()->status);
    }

    public function test_manager_can_manage_agents_but_cannot_promote_them_or_invite_managers(): void
    {
        Notification::fake();
        $owner = $this->account('team-manager-owner@example.test');
        $manager = $this->account('team-manager@example.test');
        $agent = $this->account('team-manager-agent@example.test');
        $newUser = $this->account('team-manager-new@example.test');
        $workspace = $this->workspace($owner, 'team-manager-workspace');
        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'permissions' => ['orders.view'],
            'status' => 'active',
        ]);
        $agentMembership = BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'permissions' => ['orders.view'],
            'status' => 'active',
        ]);

        $this->actingAs($manager)->put(route('workspaces.team.update', [$workspace, $agentMembership]), [
            'role' => 'manager',
            'permissions' => ['orders.view'],
        ])->assertSessionHasErrors('role');
        $this->assertSame('agent', $agentMembership->fresh()->role);

        $this->actingAs($manager)->post(route('workspaces.team.invite', $workspace), [
            'email' => $newUser->email,
            'role' => 'manager',
            'permissions' => ['orders.view'],
        ])->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('business_workspace_members', [
            'business_workspace_id' => $workspace->id,
            'user_id' => $newUser->id,
        ]);
    }

    public function test_agents_cannot_invite_and_cross_workspace_membership_ids_are_rejected(): void
    {
        Notification::fake();
        $owner = $this->account('team-isolation-owner@example.test');
        $otherOwner = $this->account('team-isolation-other-owner@example.test');
        $agent = $this->account('team-isolation-agent@example.test');
        $target = $this->account('team-isolation-target@example.test');
        $first = $this->workspace($owner, 'team-isolation-first');
        $second = $this->workspace($otherOwner, 'team-isolation-second');
        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $first->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'permissions' => ['orders.view'],
            'status' => 'active',
        ]);
        $otherMembership = BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $second->id,
            'user_id' => $target->id,
            'role' => 'agent',
            'permissions' => ['orders.view'],
            'status' => 'active',
        ]);

        $this->actingAs($agent)->post(route('workspaces.team.invite', $first), [
            'email' => $target->email,
            'role' => 'agent',
            'permissions' => ['orders.view'],
        ])->assertForbidden();

        $this->actingAs($owner)->put(route('workspaces.team.update', [$first, $otherMembership]), [
            'role' => 'admin',
            'permissions' => ['orders.view'],
        ])->assertNotFound();

        $this->actingAs($owner)->delete(route('workspaces.team.remove', [$first, $otherMembership]))
            ->assertNotFound();
        $this->assertSame('active', $otherMembership->fresh()->status);
    }

    public function test_workspace_member_can_view_team_but_agent_cannot_manage_it(): void
    {
        $owner = $this->account('team-view-owner@example.test');
        $agent = $this->account('team-view-agent@example.test');
        $workspace = $this->workspace($owner, 'team-view-workspace');
        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'permissions' => ['orders.view'],
            'status' => 'active',
        ]);

        $this->actingAs($agent)->get(route('workspaces.team', $workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Workspaces/Team')->where('canManage', false));

        $this->actingAs($agent)->delete(route('workspaces.team.remove', [
            $workspace,
            $workspace->memberships()->where('user_id', $agent->id)->firstOrFail(),
        ]))->assertForbidden();
    }
}
