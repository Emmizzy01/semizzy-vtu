<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminControlPermissionBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_approver_cannot_access_global_admin_controls(): void
    {
        $operator = User::query()->create([
            'name' => 'Refund Operator',
            'email' => 'refund-operator-admin-boundary@example.test',
            'password' => 'not-a-real-password',
        ]);
        UserPermission::query()->create([
            'user_id' => $operator->id,
            'permission' => 'refunds.approve',
        ]);

        // Refund approval is a narrow permission and must not imply platform administration.
        $this->actingAs($operator)->get('/admin/refunds')->assertOk();
        $this->actingAs($operator)->get('/admin/manual-deposits')->assertForbidden();
        $this->actingAs($operator)->get('/admin/manual-deposits/settings')->assertForbidden();
        $this->actingAs($operator)->get('/admin/feature-controls')->assertForbidden();
        $this->actingAs($operator)->get('/admin/platform-settings')->assertForbidden();
        $this->actingAs($operator)->get('/admin/staff')->assertForbidden();
    }
}
