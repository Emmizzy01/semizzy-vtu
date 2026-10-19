<?php

namespace Tests\Feature;

use App\Models\VtuProduct;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\IntegrationSetting;
use App\Services\Vtu\ProviderCatalogueImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class ProviderCatalogueImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dataway_import_creates_inactive_product_with_provider_cost_and_mapping(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);
        Http::fake([
            'dataway.ng/*' => Http::response([
                'count' => 1,
                'data' => [[
                    'id' => 1,
                    'name' => 'MTN Data',
                    'slug' => 'mtn-data',
                    'category' => 'data',
                    'plans' => [[
                        'id' => 42,
                        'name' => '1GB - 30 Days',
                        'code' => 'MTN-1GB',
                        'amount' => 300,
                        'effective_total' => 270,
                        'validity_days' => 30,
                        'benefit_type' => 'SME',
                    ]],
                ]],
            ]),
        ]);

        $result = app(ProviderCatalogueImportService::class)->import('dataway', 'data');
        $product = VtuProduct::query()->where('provider_key', 'dataway')->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('data', $product->service_type);
        $this->assertSame('mtn-data', $product->network);
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
        $this->assertSame('42', $product->metadata['provider_mappings']['dataway']['product_id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/billers/'));
    }

    public function test_provider_import_fails_closed_without_credentials(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => null,
        ]);
        Http::fake();

        try {
            app(ProviderCatalogueImportService::class)->import('sogo', 'data');
            $this->fail('Missing provider credentials must block import.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('API key', $exception->getMessage());
            Http::assertNothingSent();
        }
    }
    public function test_staff_can_save_provider_credentials_and_import_catalogue_without_exposing_key(): void
    {
        $admin = User::factory()->create();
        UserPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->put('/admin/api-providers/dataway/credentials', [
            'base_url' => 'https://dataway.ng/api/v1/merchant',
            'api_key' => 'bp_live_catalogue_test_key',
            'reason' => 'Configure Dataway catalogue import testing',
        ])->assertSessionHasNoErrors();

        $stored = IntegrationSetting::query()->where('key', 'vtu.dataway.api_key')->firstOrFail();
        $this->assertNotSame('bp_live_catalogue_test_key', $stored->getRawOriginal('value'));
        $this->assertSame('bp_live_catalogue_test_key', $stored->value);

        Http::fake([
            'dataway.ng/*' => Http::response([
                'count' => 1,
                'data' => [[
                    'id' => 1,
                    'name' => 'MTN Data',
                    'slug' => 'mtn-data',
                    'category' => 'data',
                    'plans' => [[
                        'id' => 42,
                        'name' => '1GB - 30 Days',
                        'code' => 'MTN-1GB',
                        'amount' => 300,
                        'effective_total' => 270,
                        'validity_days' => 30,
                    ]],
                ]],
            ]),
        ]);

        $this->post('/admin/provider-catalogue/dataway/import', [
            'category' => 'data',
            'reason' => 'Import Dataway data plans for pricing review',
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/v1/merchant/billers/')
            && $request->hasHeader('Authorization', 'Bearer bp_live_catalogue_test_key'));
        $this->assertDatabaseHas('vtu_products', [
            'provider_key' => 'dataway',
            'is_active' => 0,
        ]);

        $this->get('/admin/api-providers')->assertDontSee('bp_live_catalogue_test_key');
    }


    public function test_catalogue_refresh_preserves_admin_price_and_activation_decisions(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => 'dataway',
            'provider_product_code' => 'mtn-data-42',
            'provider_cost_minor' => 25000,
            'network' => 'mtn-data',
            'data_type' => 'SME',
            'validity' => '30',
            'code' => 'dataway-data-mtn-data-42',
            'name' => 'Admin-approved MTN Data',
            'price_minor' => 50000,
            'currency' => 'NGN',
            'is_active' => true,
            'pricing_by_user_category' => ['level_1' => 52000, 'level_4' => 49000],
            'commission_by_user_category' => ['level_1' => 1000, 'level_4' => 2000],
            'metadata' => ['purchase_approved' => true, 'provider_mappings' => ['dataway' => ['product_id' => '42']]],
        ]);

        Http::fake([
            'dataway.ng/*' => Http::response([
                'count' => 1,
                'data' => [[
                    'id' => 1,
                    'name' => 'MTN Data',
                    'slug' => 'mtn-data',
                    'category' => 'data',
                    'plans' => [[
                        'id' => 42,
                        'name' => '1GB - 30 Days',
                        'code' => 'MTN-1GB',
                        'amount' => 300,
                        'effective_total' => 275,
                        'validity_days' => 30,
                        'benefit_type' => 'SME',
                    ]],
                ]],
            ]),
        ]);

        $result = app(ProviderCatalogueImportService::class)->import('dataway', 'data');
        $product->refresh();

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(50000, $product->price_minor);
        $this->assertTrue($product->is_active);
        $this->assertSame(['level_1' => 52000, 'level_4' => 49000], $product->pricing_by_user_category);
        $this->assertSame(['level_1' => 1000, 'level_4' => 2000], $product->commission_by_user_category);
        $this->assertTrue($product->metadata['purchase_approved']);
        $this->assertSame(27500, $product->provider_cost_minor);
    }


    public function test_airtime_import_does_not_erase_known_provider_cost_when_provider_omits_cost(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
            'services.vtu.airtime_denominations' => [100],
        ]);

        $product = VtuProduct::query()->create([
            'service_type' => 'airtime',
            'provider_key' => 'dataway',
            'provider_product_code' => 'mtn-100',
            'provider_cost_minor' => 9250,
            'network' => 'mtn',
            'code' => 'dataway-airtime-mtn-100',
            'name' => 'MTN Airtime ₦100',
            'price_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['purchase_approved' => false],
        ]);

        Http::fake([
            'dataway.ng/*' => Http::response([
                'count' => 1,
                'data' => [['name' => 'MTN', 'slug' => 'mtn', 'category' => 'airtime']],
            ]),
        ]);

        app(ProviderCatalogueImportService::class)->import('dataway', 'airtime');
        $product->refresh();

        $this->assertSame(9250, $product->provider_cost_minor);
        $this->assertSame(10000, $product->price_minor);
        $this->assertFalse($product->is_active);
    }

    public function test_sogo_data_import_maps_network_plan_type_validity_and_cost_inactive(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_sk_test_catalogue',
        ]);

        Http::fake([
            'sandbox.sogo.africa/v1/bills/catalog*' => Http::response([
                'data' => ['data' => ['providers' => [
                    ['slug' => 'mtn', 'name' => 'MTN'],
                ]]],
            ]),
            'sandbox.sogo.africa/v1/bills/data-plans*' => Http::response([
                'data' => ['plans' => [[
                    'variation_code' => 'mtn-sme-1gb',
                    'name' => 'MTN SME 1GB',
                    'amount' => 285,
                    'type' => 'SME',
                    'validity' => '30 days',
                ]]],
            ]),
        ]);

        $result = app(ProviderCatalogueImportService::class)->import('sogo', 'data');
        $product = VtuProduct::query()->where('provider_key', 'sogo')->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('mtn', $product->network);
        $this->assertSame('SME', $product->data_type);
        $this->assertSame('30 days', $product->validity);
        $this->assertSame(28500, $product->provider_cost_minor);
        $this->assertSame('mtn-sme-1gb', $product->metadata['provider_mappings']['sogo']['variation_code']);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
    }

    public function test_gsubz_data_import_maps_plan_code_network_type_validity_and_cost_inactive(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'ap_catalogue_test_key',
        ]);

        Http::fake([
            'api.gsubz.com/api/category/*' => Http::response([
                'data' => [['serviceID' => 'mtn-data', 'name' => 'MTN Data']],
            ]),
            'api.gsubz.com/api/plans/*' => Http::response([
                'data' => [[
                    'plan' => 'legacy-plan-field',
                    'value' => 'mtn-sme-1gb',
                    'PlanName' => 'plan',
                    'api_price' => 290,
                    'validity' => '30 days',
                ]],
            ]),
        ]);

        $result = app(ProviderCatalogueImportService::class)->import('gsubz', 'data');
        $product = VtuProduct::query()->where('provider_key', 'gsubz')->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('mtn', $product->network);
        $this->assertSame('mtn-data', $product->data_type);
        $this->assertSame('30 days', $product->validity);
        $this->assertSame(29000, $product->provider_cost_minor);
        $this->assertSame('mtn-sme-1gb', $product->metadata['provider_mappings']['gsubz']['plan_code']);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
    }


    public function test_airtime_catalogue_import_is_network_based_and_amount_entered_at_checkout(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);
        Http::fake([
            'dataway.ng/*' => Http::response([
                'count' => 1,
                'data' => [['id' => 1, 'name' => 'MTN Airtime', 'slug' => 'mtn', 'category' => 'airtime']],
            ]),
        ]);

        $result = app(ProviderCatalogueImportService::class)->import('dataway', 'airtime');
        $product = VtuProduct::query()->where('provider_key', 'dataway')->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('dataway-airtime-mtn', $product->code);
        $this->assertSame('MTN Airtime', $product->name);
        $this->assertSame(0, $product->price_minor, 'Amount-based airtime must not carry a fake fixed selling price.');
        $this->assertTrue($product->metadata['amount_based']);
        $this->assertTrue($product->metadata['provider_mappings']['dataway']['amount_based']);
        $this->assertFalse($product->is_active);
    }

    public function test_malformed_provider_catalogue_does_not_create_or_activate_products(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_test_key',
        ]);

        $existing = VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => 'sogo',
            'provider_product_code' => 'existing-plan',
            'network' => 'mtn',
            'code' => 'sogo-data-mtn-existing-plan',
            'name' => 'Previously reviewed plan',
            'price_minor' => 50000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['purchase_approved' => false],
        ]);

        Http::fake([
            'sandbox.sogo.africa/v1/bills/catalog*' => Http::response([
                'data' => ['data' => ['providers' => 'malformed']],
            ]),
        ]);

        try {
            app(ProviderCatalogueImportService::class)->import('sogo', 'data');
            $this->fail('Malformed provider catalogue must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid bill catalogue', strtolower($exception->getMessage()));
        }

        $this->assertSame(1, VtuProduct::query()->count());
        $this->assertFalse($existing->fresh()->is_active);
        $this->assertFalse($existing->fresh()->metadata['purchase_approved']);
    }


    public function test_provider_api_failures_for_each_import_adapter_apply_no_catalogue_rows(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_test_key',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz_test_key',
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway_test_key',
        ]);
        Http::fake(['*' => Http::response(['message' => 'provider unavailable'], 503)]);

        foreach (['sogo', 'gsubz', 'dataway'] as $provider) {
            try {
                app(ProviderCatalogueImportService::class)->import($provider, 'data');
                $this->fail($provider.' API failure must abort its import.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('catalogue', strtolower($exception->getMessage()));
            }
        }

        $this->assertSame(0, VtuProduct::query()->count());
    }

    public function test_provider_wide_import_reports_category_failures_without_enabling_sales(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['message' => 'provider unavailable'], 503)]);

        $result = app(ProviderCatalogueImportService::class)->importAll('dataway');

        $this->assertSame(0, $result['created']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, VtuProduct::query()->count());
    }



    public function test_unsupported_service_catalogue_entries_are_inactive_and_marked_catalogue_only(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);
        Http::fake([
            'dataway.ng/*' => Http::response([
                'count' => 1,
                'data' => [[
                    'id' => 88,
                    'name' => 'Ikeja Electric',
                    'slug' => 'ikeja-electric',
                    'category' => 'electricity',
                ]],
            ]),
        ]);

        app(ProviderCatalogueImportService::class)->import('dataway', 'electricity');
        $product = VtuProduct::query()->where('provider_key', 'dataway')->firstOrFail();

        $this->assertSame('electricity-bill', $product->service_type);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_supported']);
        $this->assertTrue($product->metadata['catalog_only']);
        $this->assertFalse($product->metadata['purchase_approved']);
    }


}
