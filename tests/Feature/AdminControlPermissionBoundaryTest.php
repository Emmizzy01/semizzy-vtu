<?php

namespace Tests\\Feature;

use App\\Models\\User;
use App\\Models\\UserPermission;
use Illuminate\\Foundation\\Testing\\RefreshDatabase;
use Tests\\TestCase;

class AdminControlPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Permission Test User',
            'email' => $email,
            'password' => 'not-a-real-password',
        ]);
    }

    private function grant(User $user, string $permission): void
    {
        UserPermission::query()->create([
            'user_id' => $user->id,
            'permission' => $permission,
        ]);
    }

    public function test_ordinary_user_cannot_access_sensitive_admin_routes(): void
    {
        $user = $this->user('ordinary-boundary@example.test');

        $this->actingAs($user)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($user)->get('/admin/refunds')->assertForbidden();
        $this->actingAs($user)->get('/admin/manual-deposits')->assertForbidden();
        $this->actingAs($user)->get('/admin/manual-deposits/settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/feature-controls')->assertForbidden();
        $this->actingAs($user)->get('/admin/platform-settings')->assertForbidden();
        $this->actingAs($user)->get('/admin/staff')->assertForbidden();
        $this->actingAs($user)->put('/admin/platform-theme', [])->assertForbidden();
    }

    public function test_refund_approver_can_review_refunds_but_not_global_admin_controls(): void
    {
        $operator = $this->user('refund-operator-boundary@example.test');
        $this->grant($operator, 'refunds.approve');

        $this->actingAs($operator)->get('/admin/refunds')->assertOk();
        $this->actingAs($operator)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($operator)->put('/admin/platform-theme', [])->assertForbidden();
        $this->actingAs($operator)->get('/admin/manual-deposits')->assertForbidden();
        $this->actingAs($operator)->get('/admin/manual-deposits/settings')->assertForbidden();
        $this->actingAs($operator)->get('/admin/feature-controls')->assertForbidden();
        $this->actingAs($operator)->get('/admin/platform-settings')->assertForbidden();
        $this->actingAs($operator)->get('/admin/staff')->assertForbidden();
    }

    public function test_staff_manager_can_access_admin_controls_without_implicitly_becoming_refund_approver(): void
    {
        $manager = $this->user('staff-manager-boundary@example.test');
        $this->grant($manager, 'staff.permissions.manage');

        $this->actingAs($manager)->get('/admin/dashboard')->assertOk();
        $this->actingAs($manager)->get('/admin/platform-settings')->assertOk();
        $this->actingAs($manager)->get('/admin/staff')->assertOk();
        $this->actingAs($manager)->get('/admin/refunds')->assertForbidden();
    }
}
