<?php

namespace Tests\Feature;

use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BusinessWorkspaceManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): User
    {
        return User::query()->create([
            'name' => 'Workspace Test User',
            'email' => $email,
            'password' => 'CorrectPassword1234',
        ]);
    }

    public function test_workspace_index_only_lists_active_workspaces_for_owner_or_active_member(): void
    {
        $owner = $this->account('workspace-list-owner@example.test');
        $otherOwner = $this->account('workspace-list-other@example.test');
        $member = $this->account('workspace-list-member@example.test');

        $owned = BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Owned Workspace',
            'slug' => 'owned-workspace-test',
            'type' => 'business',
            'status' => 'active',
        ]);
        $shared = BusinessWorkspace::query()->create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Shared Workspace',
            'slug' => 'shared-workspace-test',
            'type' => 'reseller',
            'status' => 'active',
        ]);
        BusinessWorkspace::query()->create([
            'owner_user_id' => $otherOwner->id,
            'name' => 'Suspended Workspace',
            'slug' => 'suspended-workspace-test',
            'type' => 'business',
            'status' => 'suspended',
        ]);

        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $shared->id,
            'user_id' => $owner->id,
            'role' => 'agent',
            'status' => 'active',
            'permissions' => ['orders.view'],
        ]);
        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $owned->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'status' => 'invited',
        ]);

        $this->actingAs($owner)->get('/workspaces')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Workspaces/Index')
                ->has('workspaces', 2)
                ->where('workspaces.0.name', 'Shared Workspace')
                ->where('workspaces.1.name', 'Owned Workspace')
                ->where('workspaces.0.canManage', false)
                ->where('workspaces.1.canManage', true));
    }

    public function test_authenticated_user_can_create_a_business_or_reseller_workspace(): void
    {
        $owner = $this->account('workspace-create-owner@example.test');

        $this->actingAs($owner)->post('/workspaces', [
            'name' => 'My Reseller Store',
            'type' => 'reseller',
        ])->assertRedirect(route('workspaces.index'));

        $workspace = BusinessWorkspace::query()->where('owner_user_id', $owner->id)->firstOrFail();
        $this->assertSame('My Reseller Store', $workspace->name);
        $this->assertSame('reseller', $workspace->type);
        $this->assertSame('active', $workspace->status);
        $this->assertStringStartsWith('my-reseller-store-' . $owner->id, $workspace->slug);
        $this->assertTrue($workspace->canManage($owner));
    }

    public function test_workspace_creation_rejects_invalid_type_and_short_name(): void
    {
        $owner = $this->account('workspace-create-invalid@example.test');

        $this->actingAs($owner)->from('/workspaces')->post('/workspaces', [
            'name' => 'X',
            'type' => 'super-admin',
        ])->assertSessionHasErrors(['name', 'type']);

        $this->assertDatabaseCount('business_workspaces', 0);
    }

    public function test_guest_cannot_view_or_create_workspaces(): void
    {
        $this->get('/workspaces')->assertRedirect('/login');
        $this->post('/workspaces', ['name' => 'Not Allowed', 'type' => 'business'])
            ->assertRedirect('/login');
        $this->assertDatabaseCount('business_workspaces', 0);
    }
}
