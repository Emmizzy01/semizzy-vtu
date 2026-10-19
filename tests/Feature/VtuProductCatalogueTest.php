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

    public function test_provider_catalogue_sync_imports_all_categories_and_variations_inactive_and_preserves_existing_operator_price_and_status(): void
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
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/service-categories')) {
                return Http::response(['content' => [
                    ['identifier' => 'airtime', 'name' => 'Airtime Recharge'],
                    ['identifier' => 'data', 'name' => 'Data Services'],
                    ['identifier' => 'tv-subscription', 'name' => 'TV Subscription'],
                    ['identifier' => 'electricity-bill', 'name' => 'Electricity Bill'],
                    ['identifier' => 'education', 'name' => 'Education'],
                    ['identifier' => 'insurance', 'name' => 'Insurance'],
                    ['identifier' => 'other-services', 'name' => 'Other Merchants/Services'],
                ]], 200);
            }
            if (str_contains($request->url(), '/services?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $identifier = $query['identifier'] ?? '';
                $items = match ($identifier) {
                    'airtime' => [
                        ['serviceID' => 'mtn'], ['serviceID' => 'glo'], ['serviceID' => 'airtel'], ['serviceID' => 'etisalat'],
                    ],
                    'data' => [
                        ['serviceID' => 'mtn-data', 'name' => 'MTN Data'],
                        ['serviceID' => 'mtn-sme-data', 'name' => 'MTN SME Data'],
                        ['serviceID' => 'glo-data', 'name' => 'GLO Data'],
                        ['serviceID' => 'glo-sme-data', 'name' => 'GLO SME Data'],
                        ['serviceID' => 'airtel-data', 'name' => 'Airtel Data'],
                        ['serviceID' => 'etisalat-data', 'name' => '9mobile Data'],
                        ['serviceID' => 'lebara-data', 'name' => 'Lebara Data'],
                        ['serviceID' => 'smile-direct', 'name' => 'Smile Internet Data'],
                        ['serviceID' => 'spectranet', 'name' => 'Spectranet Internet Data'],
                    ],
                    'tv-subscription' => [['serviceID' => 'dstv', 'name' => 'DStv Subscription']],
                    'electricity-bill' => [['serviceID' => 'ikeja-electric', 'name' => 'Ikeja Electric']],
                    'education' => [['serviceID' => 'waec', 'name' => 'WAEC PIN']],
                    'insurance' => [['serviceID' => 'third-party', 'name' => 'Third Party Insurance']],
                    'other-services' => [['serviceID' => 'bank-deposit', 'name' => 'Bank Transfer']],
                    default => [],
                };

                return Http::response(['content' => $items], 200);
            }

            return Http::response(['content' => ['variations' => [
                ['variation_code' => 'plan-1', 'name' => '1GB SME 7 Days', 'variation_amount' => '500'],
                ['variation_code' => 'plan-corporate-gifting', 'name' => '2GB Corporate/Gifting 30 Days', 'variation_amount' => '1200'],
            ]]], 200);
        });
        $this->actingAs($staff)->post('/admin/vtu-products/sync')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vtu_products', ['code' => 'vtpass-airtime-mtn-100', 'is_active' => false]);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'data', 'is_active' => false, 'metadata->provider_service_id' => 'mtn-sme-data']);
        $smePlan = VtuProduct::query()->where('metadata->provider_service_id', 'mtn-sme-data')->firstOrFail();
        $this->assertSame('7 days', $smePlan->metadata['validity']);
        $this->assertSame('SME', $smePlan->metadata['data_type']);
        $this->assertTrue($smePlan->metadata['purchase_supported']);
        $this->assertFalse($smePlan->is_active);
        $this->assertFalse($smePlan->metadata['purchase_approved']);
        $corporateGiftingPlan = VtuProduct::query()
            ->where('metadata->provider_service_id', 'mtn-sme-data')
            ->where('metadata->provider_variation_code', 'plan-corporate-gifting')
            ->firstOrFail();
        $this->assertSame('30 days', $corporateGiftingPlan->metadata['validity']);
        $this->assertSame('Corporate/Gifting', $corporateGiftingPlan->metadata['data_type']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'data', 'is_active' => false, 'metadata->provider_service_id' => 'lebara-data']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'data', 'network' => 'smile', 'is_active' => false, 'metadata->provider_service_id' => 'smile-direct']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'data', 'network' => 'spectranet', 'is_active' => false, 'metadata->provider_service_id' => 'spectranet']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'tv-subscription', 'network' => 'DStv Subscription', 'is_active' => false, 'metadata->provider_service_id' => 'dstv']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'electricity-bill', 'network' => 'Ikeja Electric', 'is_active' => false, 'metadata->provider_category_name' => 'Electricity Bill']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'education', 'is_active' => false, 'metadata->provider_service_id' => 'waec']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'insurance', 'is_active' => false, 'metadata->provider_service_id' => 'third-party']);
        $this->assertDatabaseHas('vtu_products', ['service_type' => 'other-services', 'is_active' => false, 'metadata->provider_service_id' => 'bank-deposit', 'metadata->provider_category_name' => 'Other Merchants/Services']);
        $existing->refresh();
        $this->assertSame(99000, $existing->price_minor);
        $this->assertTrue($existing->is_active);
        $this->assertSame('keep', $existing->metadata['custom']);
        $this->assertSame('plan-1', $existing->metadata['provider_variation_code']);
        // This code already existed and was active before sync; refreshes must preserve operator status and price.
        $this->assertDatabaseHas('vtu_products', ['code' => 'vtpass-data-mtn-plan-1', 'is_active' => true]);
    }

    public function test_vtpass_categories_without_validated_purchase_flows_cannot_be_activated(): void
    {
        $staff = User::query()->create(['name' => 'Category Approval Staff', 'email' => 'category-approval@example.test', 'password' => 'not-a-real-password']);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);
        $product = VtuProduct::query()->create([
            'service_type' => 'tv-subscription',
            'network' => 'DStv',
            'code' => 'vtpass-tv-catalogue-only',
            'name' => 'DStv Premium',
            'price_minor' => 100000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['catalog_source' => 'vtpass', 'provider_service_id' => 'dstv', 'provider_variation_code' => 'premium', 'purchase_supported' => false, 'purchase_approved' => false],
        ]);

        $this->actingAs($staff)->put('/admin/vtu-products/'.$product->id, [
            'name' => $product->name,
            'price_minor' => 100000,
            'face_value_minor' => null,
            'is_active' => true,
            'purchase_approved' => true,
            'provider_service_id' => 'dstv',
            'provider_variation_code' => 'premium',
            'reason' => 'Attempt unsupported category activation',
        ])->assertUnprocessable();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertFalse($product->fresh()->metadata['purchase_approved']);
    }

    public function test_customer_can_see_mapped_inactive_vtpass_airtime_and_data_without_unmapped_drafts(): void
    {
        $user = User::query()->create([
            'name' => 'Catalogue Viewer',
            'email' => 'catalogue-viewer@example.test',
            'password' => 'not-a-real-password',
        ]);

        VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'vtpass-hidden-unmapped',
            'name' => 'Unmapped draft',
            'price_minor' => 50000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => [],
        ]);

        VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'vtpass-mapped-inactive',
            'name' => 'MTN 1GB 7 Days',
            'price_minor' => 50000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => [
                'catalog_source' => 'vtpass',
                'provider_service_id' => 'mtn-sme-data',
                'provider_variation_code' => 'plan-1',
                'catalog_source' => 'vtpass',
                'purchase_approved' => false,
            ],
        ]);

        $this->actingAs($user)
            ->getJson('/wallet/vtu/products?service_type=data')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'vtpass-mapped-inactive')
            ->assertJsonPath('data.0.is_active', false);
    }

    public function test_sogo_sandbox_catalogue_products_cannot_be_activated_for_customer_sales(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo-sandbox-test-key',
        ]);

        $staff = User::query()->create([
            'name' => 'Sogo Sandbox Approval Staff',
            'email' => 'sogo-sandbox-approval@example.test',
            'password' => 'not-a-real-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'sogo-sandbox-mtn-plan',
            'provider_key' => 'sogo',
            'provider_product_code' => 'mtn-plan-1',
            'name' => 'MTN 1GB Sandbox Plan',
            'price_minor' => 50000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => [
                'purchase_approved' => true,
                'provider_mappings' => ['sogo' => ['network' => 'mtn', 'variation_code' => 'mtn-plan-1']],
            ],
        ]);

        $this->actingAs($staff)->put('/admin/vtu-products/'.$product->id, [
            'name' => $product->name,
            'price_minor' => 50000,
            'face_value_minor' => null,
            'is_active' => true,
            'purchase_approved' => true,
            'provider_key' => 'sogo',
            'provider_product_code' => 'mtn-plan-1',
            'reason' => 'Attempt sandbox activation for sales',
        ])->assertUnprocessable();

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_vtpass_product_requires_sandbox_approval_before_activation(): void
    {
        $staff = User::query()->create([
            'name' => 'VTpass Approval Staff',
            'email' => 'vtpass-approval@example.test',
            'password' => 'not-a-real-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'vtpass-awaiting-approval',
            'name' => 'MTN 1GB 7 Days',
            'price_minor' => 50000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => [
                'catalog_source' => 'vtpass',
                'provider_service_id' => 'mtn-sme-data',
                'provider_variation_code' => 'plan-1',
                'purchase_approved' => false,
            ],
        ]);

        $payload = [
            'name' => $product->name,
            'price_minor' => 50000,
            'face_value_minor' => null,
            'is_active' => true,
            'validity' => '7 days',
            'data_type' => 'SME',
            'provider_service_id' => 'mtn-sme-data',
            'provider_variation_code' => 'plan-1',
            'purchase_approved' => false,
            'reason' => 'Attempt activation before sandbox verification',
        ];

        $this->actingAs($staff)
            ->put('/admin/vtu-products/'.$product->id, $payload)
            ->assertUnprocessable();

        $this->assertFalse($product->fresh()->is_active);

        $payload['purchase_approved'] = true;
        $payload['reason'] = 'Sandbox verified and approved for sales';
        $this->actingAs($staff)
            ->put('/admin/vtu-products/'.$product->id, $payload)
            ->assertRedirect();

        $product->refresh();
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->metadata['purchase_approved']);
        $this->assertDatabaseHas('platform_setting_audits', [
            'setting_key' => 'vtu_product.updated.'.$product->id,
        ]);
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

    public function test_catalogue_rejects_noncanonical_user_category_price_keys(): void
    {
        $staff = User::query()->create([
            'name' => 'Tier Pricing Staff',
            'email' => 'tier-pricing-staff@example.test',
            'password' => 'not-a-real-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $this->actingAs($staff)->from('/admin/vtu-products')->post('/admin/vtu-products', [
            'service_type' => 'data',
            'network' => 'MTN',
            'code' => 'invalid-tier-key-plan',
            'name' => 'Invalid tier-key plan',
            'price_minor' => 30000,
            'is_active' => false,
            'pricing_by_user_category' => ['MERCHANT' => 28000],
            'commission_by_user_category' => ['level_4' => 100],
            'reason' => 'Test canonical Semizzy tier keys',
        ])->assertSessionHasErrors('pricing_by_user_category');

        $this->assertDatabaseMissing('vtu_products', ['code' => 'invalid-tier-key-plan']);
    }

}
