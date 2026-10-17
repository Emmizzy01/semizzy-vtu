<?php

namespace Tests\Feature;

use App\Models\KycSubmission;
use App\Models\KycSubmissionAudit;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class KycSubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_evidence_is_private_and_submission_does_not_verify_user(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'KYC Customer', 'email' => 'kyc-customer@example.test', 'password' => 'not-a-real-password']);

        $this->actingAs($customer)->post('/account/kyc', [
            'document_type' => 'nin',
            'evidence' => UploadedFile::fake()->image('identity.png'),
        ])->assertRedirect();

        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();
        Storage::disk('local')->assertExists($submission->evidence_path);
        $this->assertSame('pending', $submission->status);
        $this->assertSame('pending', $customer->fresh()->kyc_status);
        $this->assertDatabaseHas('kyc_submission_audits', ['kyc_submission_id' => $submission->id, 'event_type' => 'submitted']);
        $this->actingAs($customer)->get('/account/kyc')->assertOk();
    }

    public function test_approval_requires_same_reviewer_to_open_private_evidence_first(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Evidence Owner', 'email' => 'evidence-owner@example.test', 'password' => 'not-a-real-password']);
        $reviewer = User::query()->create(['name' => 'KYC Reviewer', 'email' => 'kyc-reviewer@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $reviewer->id, 'permission' => 'staff.permissions.manage']);
        $path = UploadedFile::fake()->image('identity.png')->store('kyc-evidence', 'local');
        $submission = KycSubmission::query()->create([
            'public_id' => 'b2b9c43c-1b8f-4f1b-8a11-2f2e75d5cb02',
            'user_id' => $customer->id,
            'document_type' => 'passport',
            'evidence_path' => $path,
            'evidence_sha256' => hash('sha256', 'kyc-test-evidence'),
            'status' => 'pending',
        ]);

        $this->actingAs($reviewer)->post('/admin/kyc-reviews/'.$submission->id.'/review', [
            'decision' => 'approved', 'reason' => 'Document appears to match',
        ])->assertSessionHasErrors('decision');
        $this->assertSame('unverified', $customer->fresh()->kyc_status);

        $this->actingAs($reviewer)->get('/admin/kyc-reviews/'.$submission->id.'/evidence')->assertDownload();
        $this->assertDatabaseHas('kyc_submission_audits', ['kyc_submission_id' => $submission->id, 'actor_user_id' => $reviewer->id, 'event_type' => 'evidence_viewed']);

        $this->actingAs($reviewer)->post('/admin/kyc-reviews/'.$submission->id.'/review', [
            'decision' => 'approved', 'reason' => 'Identity evidence reviewed and matched',
        ])->assertRedirect();

        $this->assertSame('approved', $submission->fresh()->status);
        $this->assertSame('verified', $customer->fresh()->kyc_status);
        $this->assertDatabaseHas('kyc_submission_audits', ['kyc_submission_id' => $submission->id, 'actor_user_id' => $reviewer->id, 'event_type' => 'reviewed', 'outcome' => 'approved']);
        $this->actingAs($reviewer)->post('/admin/kyc-reviews/'.$submission->id.'/review', [
            'decision' => 'rejected', 'reason' => 'Attempt to change final decision',
        ])->assertSessionHasErrors('decision');
    }

    public function test_customer_cannot_access_review_queue_or_other_users_evidence(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'KYC Customer Two', 'email' => 'kyc-customer-two@example.test', 'password' => 'not-a-real-password']);
        $other = User::query()->create(['name' => 'Other User', 'email' => 'other-kyc@example.test', 'password' => 'not-a-real-password']);
        $path = UploadedFile::fake()->image('identity.png')->store('kyc-evidence', 'local');
        $submission = KycSubmission::query()->create([
            'public_id' => 'f34a21d4-4d62-41ce-9c3a-5f5f71e2b6a1',
            'user_id' => $other->id,
            'document_type' => 'nin',
            'evidence_path' => $path,
            'evidence_sha256' => hash('sha256', 'other-evidence'),
            'status' => 'pending',
        ]);

        $this->actingAs($customer)->get('/admin/kyc-reviews')->assertForbidden();
        $this->actingAs($customer)->get('/admin/kyc-reviews/'.$submission->id.'/evidence')->assertForbidden();
        $this->assertDatabaseMissing('kyc_submission_audits', ['kyc_submission_id' => $submission->id, 'event_type' => 'evidence_viewed']);
    }

    public function test_pending_submission_blocks_duplicate_submission_and_review_rejection_is_recorded(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Retry KYC Customer', 'email' => 'retry-kyc@example.test', 'password' => 'not-a-real-password']);
        $reviewer = User::query()->create(['name' => 'Reject Reviewer', 'email' => 'reject-reviewer@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $reviewer->id, 'permission' => 'staff.permissions.manage']);
        $this->actingAs($customer)->post('/account/kyc', ['document_type' => 'bvn', 'evidence' => UploadedFile::fake()->image('identity.png')])->assertRedirect();
        $this->actingAs($customer)->post('/account/kyc', ['document_type' => 'bvn', 'evidence' => UploadedFile::fake()->image('identity-again.png')])->assertSessionHasErrors('evidence');
        $submission = KycSubmission::query()->where('user_id', $customer->id)->firstOrFail();

        $this->actingAs($reviewer)->post('/admin/kyc-reviews/'.$submission->id.'/review', [
            'decision' => 'rejected', 'reason' => 'Image is unreadable; upload a clearer copy',
        ])->assertRedirect();
        $this->assertSame('rejected', $customer->fresh()->kyc_status);
        $this->assertSame('rejected', $submission->fresh()->status);
        $this->assertDatabaseHas('kyc_submission_audits', ['kyc_submission_id' => $submission->id, 'event_type' => 'reviewed', 'outcome' => 'rejected']);
    }
}
