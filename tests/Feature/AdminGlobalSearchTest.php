<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminGlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_search_requires_staff_permission_and_returns_safe_results(): void
    {
        $customer = User::query()->create(['name' => 'Search Needle Customer', 'email' => 'needle-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($customer)->get('/admin/search?q=needle')->assertForbidden();

        $admin = User::query()->create(['name' => 'Search Admin', 'email' => 'search-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->get('/admin/search?q=needle')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/GlobalSearch')
            ->where('query', 'needle')
            ->where('results.users.0.title', 'Search Needle Customer')
            ->missing('results.orders.0.raw_provider_response'));
    }
}
