<?php

namespace Tests\Feature;

use App\Models\DataRetentionPolicy;
use App\Models\PrivacyRequest;
use App\Models\SecurityIncident;
use App\Models\SecurityIncidentEvent;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyGovernanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        UserPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => null,
        ]);
        return $admin;
    }

    public function test_customer_can_submit_and_view_only_their_own_privacy_requests(): void
    {
        $customer = User::factory()->create();
        $other = User::factory()->create();
        PrivacyRequest::query()->create(['user_id' => $other->id, 'request_type' => 'deletion', 'status' => 'submitted']);

        $this->actingAs($customer)->post('/privacy/requests', [
            'request_type' => 'export',
            'reason' => 'Please provide a copy of the information held about me.',
        ])->assertRedirect();

        $this->assertDatabaseHas('privacy_requests', ['user_id' => $customer->id, 'request_type' => 'export', 'status' => 'submitted']);
        $this->actingAs($customer)->get('/privacy/requests')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Privacy/Requests')->has('requests', 1));
    }

    public function test_customer_data_export_is_private_and_limited_to_the_authenticated_account(): void
    {
        $customer = User::factory()->create(['name' => 'Private Export User']);
        $other = User::factory()->create(['name' => 'Must Not Leak']);
        PrivacyRequest::query()->create(['user_id' => $other->id, 'request_type' => 'deletion', 'status' => 'submitted']);

        $response = $this->actingAs($customer)->get('/privacy/export')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertJsonPath('profile.id', $customer->id)
            ->assertJsonMissing(['name' => 'Must Not Leak'])
            ->assertJsonPath('scope', 'Basic account profile and privacy-request history only; this is not a full platform-data export.');
    }

    public function test_duplicate_open_privacy_request_type_is_rejected(): void
    {
        $customer = User::factory()->create();
        PrivacyRequest::query()->create(['user_id' => $customer->id, 'request_type' => 'deletion', 'status' => 'in_review']);

        $this->actingAs($customer)->from('/privacy/requests')->post('/privacy/requests', [
            'request_type' => 'deletion',
            'reason' => 'Another deletion request.',
        ])->assertRedirect('/privacy/requests')->assertSessionHasErrors('request_type');

        $this->assertDatabaseCount('privacy_requests', 1);
    }

    public function test_governance_routes_require_staff_management_permission(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/governance')->assertForbidden();
        $this->actingAs($customer)->post('/admin/security-incidents', [
            'title' => 'Suspicious account access',
            'description' => 'Several unexpected login attempts were reported.',
            'severity' => 'high',
            'category' => 'account_security',
        ])->assertForbidden();
    }

    public function test_incident_lifecycle_is_audited_and_closed_incidents_cannot_be_reopened(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/security-incidents', [
            'title' => 'Unexpected login pattern',
            'description' => 'Multiple unusual login attempts were observed in a short period.',
            'severity' => 'high',
            'category' => 'account_security',
        ])->assertRedirect();

        $incident = SecurityIncident::query()->firstOrFail();
        $this->assertSame('open', $incident->status);
        $this->assertDatabaseHas('security_incident_events', ['incident_id' => $incident->id, 'event_type' => 'reported']);

        $this->actingAs($admin)->put('/admin/security-incidents/'.$incident->id, [
            'status' => 'resolved',
            'resolution_notes' => 'Reviewed access records and contained the suspicious activity.',
        ])->assertRedirect();

        $this->actingAs($admin)->put('/admin/security-incidents/'.$incident->id, [
            'status' => 'closed',
            'resolution_notes' => 'Evidence reviewed and response documented.',
        ])->assertRedirect();

        $this->assertDatabaseHas('security_incidents', ['id' => $incident->id, 'status' => 'closed']);
        $this->assertDatabaseCount('security_incident_events', 3);

        $this->actingAs($admin)->from('/admin/governance')->put('/admin/security-incidents/'.$incident->id, [
            'status' => 'open',
            'resolution_notes' => 'Attempt to reopen a closed incident.',
        ])->assertRedirect('/admin/governance')->assertSessionHasErrors('status');
    }

    public function test_retention_policy_updates_never_enable_automatic_deletion(): void
    {
        $admin = $this->admin();
        $policy = DataRetentionPolicy::query()->create([
            'record_category' => 'test_records', 'retention_days' => 365,
            'enabled' => false, 'requires_legal_review' => true,
        ]);

        $this->actingAs($admin)->put('/admin/retention-policies/'.$policy->id, [
            'retention_days' => 730,
            'enabled' => true,
            'requires_legal_review' => false,
            'notes' => 'Reviewed policy proposal.',
            'reason' => 'Approved retention period review.',
        ])->assertRedirect();

        $this->assertDatabaseHas('data_retention_policies', [
            'id' => $policy->id, 'retention_days' => 730, 'enabled' => 0, 'requires_legal_review' => 1,
        ]);
    }

    public function test_privacy_deletion_request_cannot_be_marked_fulfilled_without_manual_fulfilment_path(): void
    {
        $admin = $this->admin();
        $request = PrivacyRequest::query()->create(['user_id' => User::factory()->create()->id, 'request_type' => 'deletion', 'status' => 'approved']);

        $this->actingAs($admin)->from('/admin/governance')->put('/admin/privacy-requests/'.$request->id, [
            'status' => 'fulfilled',
            'review_note' => 'Deletion workflow has been completed and verified.',
        ])->assertRedirect('/admin/governance')->assertSessionHasErrors('status');

        $this->assertDatabaseHas('privacy_requests', ['id' => $request->id, 'status' => 'approved']);
    }
}
