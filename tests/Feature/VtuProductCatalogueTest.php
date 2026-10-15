<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuProductCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalogue_management_requires_authorised_staff_permission(): void
    {
        $user = User::query()->create(['name' => 'Catalogue Customer', 'email' => 'catalogue-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->get('/admin/vtu-products')->assertForbidden();
    }

    public function test_authorised_staff_can_create_inactive_data_plan_with_duration_and_provider_mapping(): void
    {
        $staff = User::query()->create(['name' => 'Catalogue Staff', 'email' => 'catalogue-staff@example.test', 'password' => 'not-a-real-password']);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);
        $this->actingAs($staff)->post('/admin/vtu-products', [
            'service_type' => 'data', 'network' => 'MTN', 'code' => 'mtn-test-plan',
            'name' => 'MTN 1GB 7 Days', 'price_minor' => 50000, 'face_value_minor' => null,
            'is_active' => false, 'validity' => '7 days', 'data_type' => 'SME',
            'provider_service_id' => 'mtn-data', 'provider_variation_code' => 'mtn-test-1gb',
            'reason' => 'Adding a verified catalogue plan',
        ])->assertRedirect();

        $product = VtuProduct::query()->where('code', 'mtn-test-plan')->firstOrFail();
        $this->assertFalse($product->is_active);
        $this->assertSame('7 days', $product->metadata['validity']);
        $this->assertSame('SME', $product->metadata['data_type']);
        $this->assertDatabaseHas('platform_setting_audits', ['setting_key' => 'vtu_product.created.'.$product->id]);
    }

    public function test_data_plan_cannot_be_activated_without_provider_variation_code(): void
    {
        $staff = User::query()->create(['name' => 'Catalogue Staff Two', 'email' => 'catalogue-staff-two@example.test', 'password' => 'not-a-real-password']);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);
        $this->actingAs($staff)->post('/admin/vtu-products', [
            'service_type' => 'data', 'network' => 'MTN', 'code' => 'mtn-missing-map',
            'name' => 'Unmapped plan', 'price_minor' => 50000, 'is_active' => true,
            'validity' => '1 day', 'data_type' => 'Direct', 'provider_service_id' => 'mtn-data',
            'provider_variation_code' => '', 'reason' => 'Test unmapped activation',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('vtu_products', ['code' => 'mtn-missing-map']);
    }
}
