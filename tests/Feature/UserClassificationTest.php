<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserRoleAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_update_user_type_level_and_kyc_with_audit(): void
    {
        $admin = User::query()->create(['name' => 'Classification Admin', 'email' => 'classification-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $customer = User::query()->create(['name' => 'Classification Customer', 'email' => 'classification-customer@example.test', 'password' => 'not-a-real-password']);

        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->put('/admin/users/'.$customer->id.'/classification', [
            'user_type' => 'reseller', 'user_level' => 'level_2', 'kyc_status' => 'pending',
            'reason' => 'Customer requested reseller onboarding',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'user_type' => 'reseller', 'user_level' => 'level_2', 'kyc_status' => 'pending']);
        $this->assertDatabaseHas('user_role_audits', ['target_user_id' => $customer->id, 'actor_user_id' => $admin->id, 'new_user_type' => 'reseller', 'new_user_level' => 'level_2']);
        $this->assertDatabaseMissing('user_permissions', ['user_id' => $customer->id, 'permission' => 'staff.permissions.manage']);
        $this->assertGreaterThan(0, UserRoleAudit::query()->count());
    }

    public function test_admin_cannot_mark_kyc_verified_without_identity_evidence_review(): void
    {
        $admin = User::query()->create(['name' => 'KYC Admin', 'email' => 'kyc-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $customer = User::query()->create(['name' => 'Unverified Customer', 'email' => 'unverified-customer@example.test', 'password' => 'not-a-real-password']);

        $this->actingAs($admin)->put('/admin/users/'.$customer->id.'/classification', [
            'user_type' => 'customer', 'user_level' => 'level_1', 'kyc_status' => 'verified',
            'reason' => 'Attempt to verify without evidence',
        ])->assertSessionHasErrors('kyc_status');

        $this->assertDatabaseHas('users', ['id' => $customer->id, 'kyc_status' => 'unverified']);
        $this->assertDatabaseMissing('user_role_audits', ['target_user_id' => $customer->id]);
    }

    public function test_customers_cannot_manage_classification_and_admin_cannot_change_self(): void
    {
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'classification-customer2@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($customer)->get('/admin/users')->assertForbidden();
        $this->actingAs($customer)->put('/admin/users/'.$customer->id.'/classification', [
            'user_type' => 'administrator', 'user_level' => 'level_4', 'kyc_status' => 'verified',
            'reason' => 'Attempted self privilege escalation',
        ])->assertForbidden();
    }

    public function test_admin_cannot_change_own_classification_even_with_valid_payload(): void
    {
        $admin = User::query()->create(['name' => 'Self Classification Admin', 'email' => 'self-classification-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);

        $this->actingAs($admin)->put('/admin/users/'.$admin->id.'/classification', [
            'user_type' => 'customer', 'user_level' => 'level_1', 'kyc_status' => 'unverified',
            'reason' => 'Attempt to change own account classification',
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'user_type' => 'customer', 'user_level' => 'level_1', 'kyc_status' => 'unverified']);
        $this->assertDatabaseMissing('user_role_audits', ['target_user_id' => $admin->id]);
    }

    public function test_admin_user_list_filters_by_type_level_and_kyc_status(): void
    {
        $admin = User::query()->create(['name' => 'Filter Admin', 'email' => 'filter-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $matching = User::query()->create(['name' => 'Matching Merchant', 'email' => 'matching-merchant@example.test', 'password' => 'not-a-real-password']);
        $matching->forceFill(['user_type' => 'vendor', 'user_level' => 'level_4', 'kyc_status' => 'pending'])->save();
        $nonmatching = User::query()->create(['name' => 'Nonmatching Customer', 'email' => 'nonmatching-customer@example.test', 'password' => 'not-a-real-password']);
        $nonmatching->forceFill(['user_type' => 'customer', 'user_level' => 'level_1', 'kyc_status' => 'unverified'])->save();

        $this->actingAs($admin)->get('/admin/users?type=vendor&level=level_4&kyc=pending')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->where('filters.type', 'vendor')
                ->where('filters.level', 'level_4')
                ->where('filters.kyc', 'pending')
                ->has('users.data', 1)
                ->where('users.data.0.id', $matching->id));
    }

}
