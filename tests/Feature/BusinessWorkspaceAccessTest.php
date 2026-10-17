<?php

namespace Tests\Feature;

use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessWorkspaceAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_owner_and_active_manager_can_manage_but_agents_cannot(): void
    {
        $owner = User::query()->create([
            'name' => 'Workspace Owner',
            'email' => 'workspace-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $manager = User::query()->create([
            'name' => 'Workspace Manager',
            'email' => 'workspace-manager@example.test',
            'password' => 'not-a-real-password',
        ]);
        $agent = User::query()->create([
            'name' => 'Workspace Agent',
            'email' => 'workspace-agent@example.test',
            'password' => 'not-a-real-password',
        ]);

        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Semizzy Reseller',
            'slug' => 'semizzy-reseller',
            'type' => 'reseller',
            'status' => 'active',
        ]);

        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'status' => 'active',
            'permissions' => ['members.view', 'orders.view'],
        ]);
        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'status' => 'active',
            'permissions' => ['orders.view'],
        ]);

        $this->assertTrue($workspace->canManage($owner));
        $this->assertTrue($workspace->canManage($manager));
        $this->assertFalse($workspace->canManage($agent));
        $this->assertTrue($workspace->hasActiveMember($agent));
        $this->assertTrue($workspace->membershipFor($manager)->hasPermission('orders.view'));
        $this->assertFalse($workspace->membershipFor($manager)->hasPermission('members.manage'));
    }

    public function test_invited_suspended_and_cross_workspace_users_are_not_active_members(): void
    {
        $owner = User::query()->create([
            'name' => 'First Owner',
            'email' => 'first-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $otherOwner = User::query()->create([
            'name' => 'Other Owner',
            'email' => 'other-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $member = User::query()->create([
            'name' => 'Invited Member',
            'email' => 'invited-member@example.test',
            'password' => 'not-a-real-password',
        ]);

        $first = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'First Workspace',
            'slug' => 'first-workspace',
            'type' => 'business',
            'status' => 'active',
        ]);
        $second = BusinessWorkspace::query()->create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Second Workspace',
            'slug' => 'second-workspace',
            'type' => 'business',
            'status' => 'active',
        ]);

        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $first->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'status' => 'invited',
        ]);

        $this->assertFalse($first->hasActiveMember($member));
        $this->assertFalse($first->canManage($member));
        $this->assertFalse($second->hasActiveMember($member));
        $this->assertNull($second->membershipFor($member));

        $membership = $first->memberships()->firstOrFail();
        $membership->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse($first->hasActiveMember($member));
        $this->assertNull($first->membershipFor($member));
    }

    public function test_suspended_workspace_denies_owner_and_member_access_helpers(): void
    {
        $owner = User::query()->create([
            'name' => 'Suspended Owner',
            'email' => 'suspended-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $workspace = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Suspended Workspace',
            'slug' => 'suspended-workspace',
            'type' => 'business',
            'status' => 'suspended',
        ]);

        $this->assertFalse($workspace->canManage($owner));
        $this->assertFalse($workspace->hasActiveMember($owner));
    }
}
