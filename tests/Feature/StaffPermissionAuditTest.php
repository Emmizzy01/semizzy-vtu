<?php

namespace Tests\Feature;

use App\Models\StaffPermissionAudit;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPermissionAuditTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Permission Audit Test User',
            'email' => $email,
            'password' => 'not-a-real-password',
        ]);
    }

    private function manager(): User
    {
        $manager = $this->user('permission-manager@example.test');
        UserPermission::query()->create([
            'user_id' => $manager->id,
            'permission' => 'staff.permissions.manage',
        ]);

        return $manager;
    }

    public function test_grant_and_revoke_are_audited_with_actor_target_and_reason(): void
    {
        $manager = $this->manager();
        $target = $this->user('permission-target@example.test');

        $this->actingAs($manager)->post('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Assigned refund review duties',
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
            'reason' => 'Assigned refund review duties',
        ]);

        $this->actingAs($manager)->delete('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Refund review assignment ended',
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
            'reason' => 'Refund review assignment ended',
        ]);
        $this->assertSame(2, StaffPermissionAudit::query()->count());
    }

    public function test_repeated_grant_or_revoke_does_not_create_duplicate_audit_events(): void
    {
        $manager = $this->manager();
        $target = $this->user('permission-repeat-target@example.test');

        $payload = ['permission' => 'refunds.approve', 'reason' => 'Approved for refund reviews'];
        $this->actingAs($manager)->post('/admin/staff/users/'.$target->id.'/permissions', $payload)
            ->assertOk()->assertJsonPath('changed', true);
        $this->actingAs($manager)->post('/admin/staff/users/'.$target->id.'/permissions', $payload)
            ->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(1, StaffPermissionAudit::query()->count());

        $revoke = ['permission' => 'refunds.approve', 'reason' => 'No longer assigned to reviews'];
        $this->actingAs($manager)->delete('/admin/staff/users/'.$target->id.'/permissions', $revoke)
            ->assertOk()->assertJsonPath('changed', true);
        $this->actingAs($manager)->delete('/admin/staff/users/'.$target->id.'/permissions', $revoke)
            ->assertOk()->assertJsonPath('changed', false);
        $this->assertSame(2, StaffPermissionAudit::query()->count());
    }

    public function test_invalid_permission_or_short_reason_is_rejected_without_audit(): void
    {
        $manager = $this->manager();
        $target = $this->user('permission-invalid-target@example.test');

        $this->actingAs($manager)->post('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'staff.permissions.manage',
            'reason' => 'Legitimate reason',
        ])->assertSessionHasErrors('permission');

        $this->actingAs($manager)->post('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'short',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(0, StaffPermissionAudit::query()->count());
        $this->assertSame(0, UserPermission::query()->where('user_id', $target->id)->count());
    }

    public function test_manager_cannot_change_their_own_permission_and_attempt_is_not_audited(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post('/admin/staff/users/'.$manager->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Attempted self assignment',
        ])->assertSessionHasErrors('user');

        $this->assertSame(0, StaffPermissionAudit::query()->count());
        $this->assertDatabaseMissing('user_permissions', [
            'user_id' => $manager->id,
            'permission' => 'refunds.approve',
        ]);
    }

    public function test_ordinary_user_cannot_grant_or_revoke_permissions(): void
    {
        $ordinary = $this->user('ordinary-permission-audit@example.test');
        $target = $this->user('ordinary-permission-target@example.test');

        $this->actingAs($ordinary)->post('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Attempted unauthorized grant',
        ])->assertForbidden();

        $this->actingAs($ordinary)->delete('/admin/staff/users/'.$target->id.'/permissions', [
            'permission' => 'refunds.approve',
            'reason' => 'Attempted unauthorized revoke',
        ])->assertForbidden();

        $this->assertSame(0, StaffPermissionAudit::query()->count());
    }
}
