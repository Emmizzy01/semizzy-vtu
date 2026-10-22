<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDirectUrlAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_urls_redirect_guests_to_login(): void
    {
        $this->get('/admin/dashboard')->assertRedirect('/login');
        $this->get('/admin/refunds')->assertRedirect('/login');
    }

    public function test_each_platform_role_is_authorized_by_capability_for_direct_admin_urls(): void
    {
        $cases = [
            'ADMIN' => [['staff.permissions.manage', 'refunds.approve'], 200, 200],
            'STAFF' => [['staff.permissions.manage'], 200, 403],
            'SUPPORT' => [['refunds.approve'], 403, 200],
            'USER' => [[], 403, 403],
        ];

        foreach ($cases as $role => [$permissions, $dashboardStatus, $refundsStatus]) {
            $user = User::factory()->create(['name' => $role.' role test']);
            foreach ($permissions as $permission) {
                UserPermission::query()->create([
                    'user_id' => $user->id,
                    'permission' => $permission,
                ]);
            }

            $this->actingAs($user)
                ->get('/admin/dashboard')
                ->assertStatus($dashboardStatus);

            $this->actingAs($user)
                ->get('/admin/refunds')
                ->assertStatus($refundsStatus);
        }
    }

    public function test_suspended_staff_is_logged_out_before_accessing_admin_routes(): void
    {
        $staff = User::factory()->create([
            'account_status' => 'suspended',
        ]);
        UserPermission::query()->create([
            'user_id' => $staff->id,
            'permission' => 'staff.permissions.manage',
        ]);

        $this->actingAs($staff)
            ->get('/admin/platform-settings')
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->actingAs($staff)->get('/admin/dashboard')->assertRedirect('/login');
    }
}
