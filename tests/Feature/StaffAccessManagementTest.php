<?php

namespace Tests\Feature;

use App\Models\StaffPermissionAudit;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAccessManagementTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): User
    {
        return User::query()->create([
            'name' => ucfirst(strtok($email, '@')),
            'email' => $email,
            'password' => 'test-password',
        ]);
    }

    private function grant(User $user, string $permission): void
    {
        UserPermission::query()->create([
            'user_id' => $user->id,
            'permission' => $permission,
        ]);
    }

    public function test_staff_workspace_requires_dedicated_management_permission(): void
    {
        $user = $this->account('finance@example.test');

        $this->actingAs($user)->get('/admin/staff')->assertForbidden();

        $this->grant($user, 'refunds.approve');
        $this->actingAs($user)->get('/admin/staff')->assertForbidden();

        $this->grant($user, 'staff.permissions.manage');
        $this->actingAs($user)->get('/admin/staff')->assertOk();
        $this->actingAs($user)->getJson('/admin/staff/users')->assertOk();
        $this->actingAs($user)->getJson('/admin/staff/permission-audits')->assertOk();
    }

    public function test_manager_can_grant_and_revoke_refund_access_with_audit_reason(): void
    {
        $manager = $this->account('manager@example.test');
        $target = $this->account('operator@example.test');
        $this->grant($manager, 'staff.permissions.manage');

        $this->actingAs($manager)->postJson('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Assigned to the finance approval rotation.',
        ])->assertOk()->assertJsonPath('changed', true);

        $this->assertDatabaseHas('user_permissions', [
            'user_id' => $target->id,
            'permission' => 'refunds.approve',
            'granted_by_user_id' => $manager->id,
        ]);
        $this->assertDatabaseHas('staff_permission_audits', [
            'actor_user_id' => $manager->id,
            'target_user_id' => $target->id,
            'permission' => 'refunds.approve',
            'action' => 'granted',
            'reason' => 'Assigned to the finance approval rotation.',
        ]);

        $this->actingAs($manager)->deleteJson('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Finance rotation assignment has ended.',
        ])->assertOk()->assertJsonPath('changed', true);

        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $target->id,
            'permission' => 'refunds.approve',
        ]);
        $this->assertDatabaseHas('staff_permission_audits', [
            'actor_user_id' => $manager->id,
            'target_user_id' => $target->id,
            'permission' => 'refunds.approve',
            'action' => 'revoked',
            'reason' => 'Finance rotation assignment has ended.',
        ]);
        $this->assertSame(2, StaffPermissionAudit::query()->count());
    }

    public function test_cannot_change_own_permissions_or_manage_unapproved_permission_names(): void
    {
        $manager = $this->account('manager@example.test');
        $target = $this->account('operator@example.test');
        $this->grant($manager, 'staff.permissions.manage');

        $this->actingAs($manager)->postJson('/admin/staff/users/'.$manager->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Attempt to grant myself access.',
        ])->assertUnprocessable();

        $this->actingAs($manager)->postJson('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'staff.permissions.manage',
            'reason' => 'Attempt to delegate access management.',
        ])->assertUnprocessable();

        $this->actingAs($manager)->postJson('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'short',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('staff_permission_audits', 0);
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $target->id,
            'permission' => 'refunds.approve',
        ]);
    }

    public function test_permission_management_endpoints_are_forbidden_to_regular_users(): void
    {
        $user = $this->account('customer@example.test');
        $target = $this->account('operator@example.test');

        $this->actingAs($user)->getJson('/admin/staff/users')->assertForbidden();
        $this->actingAs($user)->postJson('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Unauthorized access test.',
        ])->assertForbidden();

        $this->assertDatabaseCount('staff_permission_audits', 0);
    }
}
