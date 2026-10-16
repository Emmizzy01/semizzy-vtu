<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuProduct;
use Illuminate\Support\Facades\Http;
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

    public function test_provider_catalogue_sync_adds_products_inactive_and_preserves_existing_operator_price_and_status(): void
    {
        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'test-key',
            'services.vtu.vtpass.secret_key' => 'test-secret',
            'services.vtu.airtime_denominations' => [100],
        ]);
        $staff = User::query()->create(['name' => 'Sync Staff', 'email' => 'sync-staff@example.test', 'password' => 'not-a-real-password']);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);
        $existing = VtuProduct::query()->create([
            'service_type' => 'data', 'network' => 'mtn', 'code' => 'vtpass-data-mtn-plan-1',
            'name' => 'Operator name', 'price_minor' => 99000, 'currency' => 'NGN',
            'is_active' => true, 'metadata' => ['provider_variation_code' => 'old-code', 'custom' => 'keep'],
        ]);
        Http::fake([
            'sandbox.vtpass.com/api/services*' => Http::response(['content' => [
                ['serviceID' => 'mtn'], ['serviceID' => 'glo'], ['serviceID' => 'airtel'], ['serviceID' => 'etisalat'],
                ['serviceID' => 'mtn-data'], ['serviceID' => 'glo-data'], ['serviceID' => 'airtel-data'], ['serviceID' => 'etisalat-data'],
            ]], 200),
            'sandbox.vtpass.com/api/service-variations*' => Http::response(['content' => ['variations' => [
                ['variation_code' => 'plan-1', 'name' => '1GB SME 7 Days', 'variation_amount' => '500'],
            ]]], 200),
        ]);
        $this->actingAs($staff)->post('/admin/vtu-products/sync')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vtu_products', ['code' => 'vtpass-airtime-mtn-100', 'is_active' => false]);
        $existing->refresh();
        $this->assertSame(99000, $existing->price_minor);
        $this->assertTrue($existing->is_active);
        $this->assertSame('keep', $existing->metadata['custom']);
        $this->assertSame('plan-1', $existing->metadata['provider_variation_code']);
        $this->assertDatabaseHas('vtu_products', ['code' => 'vtpass-data-mtn-plan-1', 'is_active' => false]);
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
