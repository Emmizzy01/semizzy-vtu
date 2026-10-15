<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Services\Security\TransactionPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminSecurityEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_events_are_permission_gated_and_show_only_safe_context(): void
    {
        $customer = User::query()->create(['name' => 'Security Customer', 'email' => 'security-customer@example.test', 'password' => 'not-a-real-password']);
        app(TransactionPinService::class)->record($customer->id, 'pin_verification', 'failed', ['attempts' => 1]);
        $this->actingAs($customer)->get('/admin/security-events')->assertForbidden();

        $admin = User::query()->create(['name' => 'Security Admin', 'email' => 'security-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($admin)->get('/admin/security-events')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/SecurityEvents')
            ->where('events.data.0.event_type', 'pin_verification')
            ->where('events.data.0.context.attempts', 1)
            ->missing('events.data.0.pin')
            ->missing('events.data.0.ip_address'));
    }
}
