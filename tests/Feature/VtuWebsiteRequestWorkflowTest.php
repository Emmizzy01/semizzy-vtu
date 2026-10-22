<?php

namespace Tests\Feature;

use App\Models\PlatformFeatureControl;
use App\Models\PlatformAddon;
use App\Services\Addons\AddonLifecyclePolicy;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuWebsiteRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuWebsiteRequestWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $email = 'website-request-customer@example.test'): User
    {
        return User::query()->create(['name' => 'Website Request Customer', 'email' => $email, 'password' => 'not-a-real-password']);
    }

    public function test_customer_can_submit_request_when_feature_is_enabled_and_submission_is_audited(): void
    {
        $customer = $this->customer();
        PlatformFeatureControl::query()->where('feature_key', 'service.vtu_website_requests')
            ->update(['is_enabled' => true, 'is_implemented' => true]);
        PlatformAddon::query()->updateOrCreate(
            ['addon_key' => 'service.vtu-website-requests'],
            [
                'name' => 'VTU Website Requests',
                'version' => '1.0.0',
                'minimum_core_version' => '1.0.0',
                'status' => 'enabled',
                'compatibility_status' => 'compatible',
                'is_core_owned' => false,
            ],
        );

        $this->actingAs($customer)->post('/service-requests/vtu-website', [
            'request_type' => 'new_vtu_site',
            'business_name' => 'Example VTU',
            'contact_phone' => '080 1234 5678',
            'budget' => '50000',
            'description' => 'I need a VTU website with customer accounts, wallet funding and service purchase pages.',
        ])->assertRedirect(route('vtu-website-requests.index'));

        $this->assertDatabaseHas('vtu_website_requests', [
            'user_id' => $customer->id,
            'request_type' => 'new_vtu_site',
            'contact_phone' => '08012345678',
            'budget_minor' => 5000000,
            'status' => 'submitted',
        ]);
        $this->assertDatabaseHas('vtu_website_request_events', [
            'action' => 'submitted',
            'new_status' => 'submitted',
        ]);
    }

    public function test_customer_cannot_submit_when_feature_is_disabled(): void
    {
        $customer = $this->customer('website-request-disabled@example.test');
        PlatformFeatureControl::query()->where('feature_key', 'service.vtu_website_requests')
            ->update(['is_enabled' => false, 'is_implemented' => true]);

        $this->actingAs($customer)->post('/service-requests/vtu-website', [
            'request_type' => 'maintenance',
            'contact_phone' => '08012345678',
            'description' => 'Please maintain and update my existing VTU website service pages.',
        ])->assertStatus(503);

        $this->assertDatabaseCount('vtu_website_requests', 0);
        $this->assertDatabaseCount('vtu_website_request_events', 0);
    }

    public function test_addon_disabled_between_preflight_and_insert_blocks_new_request(): void
    {
        $customer = $this->customer('website-request-addon-race@example.test');
        PlatformFeatureControl::query()->where('feature_key', 'service.vtu_website_requests')
            ->update(['is_enabled' => true, 'is_implemented' => true]);
        PlatformAddon::query()->updateOrCreate(
            ['addon_key' => 'service.vtu-website-requests'],
            [
                'name' => 'VTU Website Requests',
                'version' => '1.0.0',
                'minimum_core_version' => '1.0.0',
                'status' => 'enabled',
                'compatibility_status' => 'compatible',
                'is_core_owned' => false,
            ],
        );

        // Simulate an administrator disabling the addon after preflight but
        // before the insert transaction reaches its admission recheck.
        $policy = $this->mock(AddonLifecyclePolicy::class);
        $policy->shouldReceive('assessAdmission')->twice()->andReturn(
            ['available' => true, 'reason' => 'ready'],
            ['available' => false, 'reason' => 'addon_disabled'],
        );

        $this->actingAs($customer)->post('/service-requests/vtu-website', [
            'request_type' => 'maintenance',
            'contact_phone' => '08012345678',
            'description' => 'Please maintain and update my existing VTU website service pages.',
        ])->assertStatus(503);

        $this->assertDatabaseCount('vtu_website_requests', 0);
        $this->assertDatabaseCount('vtu_website_request_events', 0);
    }

    public function test_authorized_staff_can_review_request_and_record_status_history(): void
    {
        $staff = $this->customer('website-request-staff@example.test');
        UserPermission::query()->create(['user_id' => $staff->id, 'permission' => 'staff.permissions.manage']);
        $customer = $this->customer('website-request-owner@example.test');
        $item = VtuWebsiteRequest::query()->create([
            'public_id' => 'VWR-TEST12345',
            'user_id' => $customer->id,
            'request_type' => 'api_integration',
            'contact_phone' => '08012345678',
            'description' => 'Connect the approved API provider to my VTU business service.',
            'status' => 'submitted',
        ]);

        $this->actingAs($staff)->put('/admin/vtu-website-requests/'.$item->id.'/status', [
            'status' => 'in_review',
            'admin_note' => 'We are reviewing the API requirements and provider compatibility.',
        ])->assertRedirect(route('admin.vtu-website-requests.index'));

        $this->assertDatabaseHas('vtu_website_requests', [
            'id' => $item->id,
            'status' => 'in_review',
            'reviewed_by_user_id' => $staff->id,
        ]);
        $this->assertDatabaseHas('vtu_website_request_events', [
            'request_id' => $item->id,
            'actor_user_id' => $staff->id,
            'old_status' => 'submitted',
            'new_status' => 'in_review',
            'action' => 'admin_updated',
        ]);
    }
}
