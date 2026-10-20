<?php

namespace Tests\Feature;

use App\Models\VtuProduct;
use App\Models\User;
use App\Models\UserPermission;
use App\Models\IntegrationSetting;
use App\Services\Vtu\ProviderCatalogueImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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


    public function test_dataway_repeated_catalogue_import_is_idempotent_and_preserves_operator_price(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway_test_key',
        ]);
        Http::fake([
            'dataway.ng/*' => Http::response([
                'data' => [[
                    'name' => 'MTN Data',
                    'slug' => 'mtn-data',
                    'plans' => [[
                        'id' => 42,
                        'name' => 'MTN SME 1GB',
                        'effective_total' => 270,
                        'validity_days' => 30,
                        'benefit_type' => 'SME',
                    ]],
                ]],
            ]),
        ]);

        $service = app(ProviderCatalogueImportService::class);
        $first = $service->import('dataway', 'data');
        $product = VtuProduct::query()->where('provider_key', 'dataway')->firstOrFail();
        $product->forceFill(['price_minor' => 34000])->save();
        $second = $service->import('dataway', 'data');
        $product->refresh();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, VtuProduct::query()->where('provider_key', 'dataway')->count());
        $this->assertSame(34000, $product->price_minor);
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertSame('SME', $product->data_type);
        $this->assertSame('30', $product->validity);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
    }

    public function test_customer_catalogue_hides_provider_identity_and_internal_mapping_fields(): void
    {
        $user = User::factory()->create();
        VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => 'gsubz',
            'provider_product_code' => 'internal-mtn-sme-1gb',
            'provider_cost_minor' => 25000,
            'network' => 'mtn',
            'code' => 'gsubz-data-internal-mtn-sme-1gb',
            'name' => 'MTN SME 1GB — 30 days',
            'price_minor' => 30000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => [
                'catalog_source' => 'gsubz',
                'provider_service_id' => 'mtn_sme',
                'provider_variation_code' => 'internal-mtn-sme-1gb',
                'provider_mappings' => ['gsubz' => ['network' => 'mtn_sme', 'plan_code' => 'internal-mtn-sme-1gb']],
                'purchase_approved' => false,
                'validity' => '30 days',
                'data_type' => 'SME',
            ],
        ]);

        $response = $this->actingAs($user)->getJson('/wallet/vtu/products?service_type=data');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'MTN SME 1GB — 30 days')
            ->assertJsonPath('data.0.metadata.validity', '30 days')
            ->assertJsonPath('data.0.metadata.data_type', 'SME')
            ->assertJsonMissingPath('data.0.provider_key')
            ->assertJsonMissingPath('data.0.provider_product_code')
            ->assertJsonMissingPath('data.0.provider_cost_minor')
            ->assertJsonMissingPath('data.0.metadata.catalog_source')
            ->assertJsonMissingPath('data.0.metadata.provider_service_id')
            ->assertJsonMissingPath('data.0.metadata.provider_variation_code')
            ->assertJsonMissingPath('data.0.metadata.provider_mappings');
        $this->assertStringNotContainsString('internal-mtn-sme-1gb', $response->getContent());
        $this->assertStringNotContainsString('gsubz', strtolower($response->getContent()));
    }

    public function test_sogo_repeated_catalogue_import_is_idempotent_and_refreshes_only_provider_facts(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_sk_test_catalogue',
        ]);
        Http::fake([
            'sandbox.sogo.africa/v1/bills/catalog*' => Http::response([
                'data' => ['data' => ['providers' => [['slug' => 'mtn', 'name' => 'MTN']]]],
            ]),
            'sandbox.sogo.africa/v1/bills/data-plans*' => Http::response([
                'data' => ['plans' => [[
                    'variation_code' => 'mtn-sme-1gb', 'name' => 'MTN SME 1GB',
                    'amount' => 285, 'type' => 'SME', 'validity' => '30 days',
                ]]],
            ]),
        ]);

        $service = app(ProviderCatalogueImportService::class);
        $first = $service->import('sogo', 'data');
        $product = VtuProduct::query()->where('provider_key', 'sogo')->firstOrFail();
        $product->forceFill([
            'price_minor' => 35000,
            'is_active' => false,
            'metadata' => array_merge($product->metadata ?? [], ['purchase_approved' => false]),
        ])->save();

        $second = $service->import('sogo', 'data');
        $product->refresh();

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, VtuProduct::query()->where('provider_key', 'sogo')->count());
        $this->assertSame(35000, $product->price_minor, 'Re-import must not overwrite the admin selling price.');
        $this->assertFalse($product->is_active, 'Re-import must not enable customer sales.');
        $this->assertFalse($product->metadata['purchase_approved']);
        $this->assertSame(28500, $product->provider_cost_minor);
        $this->assertSame('30 days', $product->validity);
    }

    public function test_gsubz_repeated_catalogue_import_is_idempotent_and_keeps_products_inactive(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'ap_catalogue_test_key',
        ]);
        Http::fake([
            'api.gsubz.com/api/category/*' => Http::response([
                'data' => [['id' => 'mtn_sme', 'name' => 'MTN SME Data']],
            ]),
            'api.gsubz.com/api/plans/*' => Http::response([
                'data' => [[
                    'value' => 'mtn-sme-1gb-30d',
                    'name' => 'MTN SME 1GB 30 days',
                    'api_price' => 250,
                    'validity' => '30 days',
                ]],
            ]),
        ]);

        $service = app(ProviderCatalogueImportService::class);
        $first = $service->import('gsubz', 'data');
        $second = $service->import('gsubz', 'data');

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['updated']);
        $this->assertSame(1, VtuProduct::query()->where('provider_key', 'gsubz')->count());
        $product = VtuProduct::query()->where('provider_key', 'gsubz')->firstOrFail();
        $this->assertSame('mtn_sme-mtn-sme-1gb-30d', $product->provider_product_code);
        $this->assertSame(25000, $product->provider_cost_minor);
        $this->assertSame('30 days', $product->validity);
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

    public function test_catalogue_refresh_deactivates_previously_approved_product_when_it_becomes_catalog_only(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);

        $product = VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'provider_key' => 'dataway',
            'provider_product_code' => 'ikeja-electric',
            'provider_cost_minor' => 10000,
            'network' => 'ikeja-electric',
            'code' => 'dataway-electricity-ikeja-electric',
            'name' => 'Previously approved electricity product',
            'price_minor' => 15000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => [
                'catalog_source' => 'dataway',
                'purchase_approved' => true,
                'purchase_supported' => true,
                'catalog_only' => false,
                'provider_mappings' => ['dataway' => ['product_id' => '42']],
            ],
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

        $result = app(ProviderCatalogueImportService::class)->import('dataway', 'electricity');
        $product->refresh();

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
        $this->assertFalse($product->metadata['purchase_supported']);
        $this->assertTrue($product->metadata['catalog_only']);
    }


    public function test_sogo_catalogue_timeout_after_a_partial_response_does_not_import_partial_products(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_test_key',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/bills/catalog')) {
                return Http::response([
                    'data' => ['data' => ['providers' => [
                        ['slug' => 'mtn', 'name' => 'MTN'],
                        ['slug' => 'airtel', 'name' => 'Airtel'],
                    ]]],
                ]);
            }

            if (str_contains($request->url(), '/bills/data-plans') && str_contains($request->url(), 'network=mtn')) {
                return Http::response([
                    'data' => ['plans' => [[
                        'variation_code' => 'mtn-sme-1gb',
                        'name' => 'MTN SME 1GB',
                        'amount' => 285,
                        'type' => 'SME',
                        'validity' => '30 days',
                    ]]],
                ]);
            }

            if (str_contains($request->url(), '/bills/data-plans') && str_contains($request->url(), 'network=airtel')) {
                throw new ConnectionException('Connection timed out.');
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });

        try {
            app(ProviderCatalogueImportService::class)->import('sogo', 'data');
            $this->fail('A catalogue timeout must abort the import instead of saving a partial provider snapshot.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }

        $this->assertSame(0, VtuProduct::query()->count());
    }


    public function test_dataway_malformed_catalogue_missing_data_field_aborts_without_mutating_existing_product(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => 'dataway',
            'provider_product_code' => 'mtn-plan-42',
            'provider_cost_minor' => 27000,
            'network' => 'mtn',
            'code' => 'dataway-data-mtn-plan-42',
            'name' => 'Reviewed MTN plan',
            'price_minor' => 32000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['purchase_approved' => false, 'provider_mappings' => ['dataway' => ['product_id' => '42']]],
        ]);

        Http::fake(['dataway.ng/*' => Http::response(['message' => 'success but data omitted'])]);

        try {
            app(ProviderCatalogueImportService::class)->import('dataway', 'data');
            $this->fail('A missing data field must not be treated as a valid empty catalogue.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid biller catalogue', strtolower($exception->getMessage()));
        }

        $product->refresh();
        $this->assertSame(1, VtuProduct::query()->count());
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertSame(32000, $product->price_minor);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
    }

    public function test_dataway_explicit_empty_catalogue_does_not_delete_or_activate_existing_products(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'test-key',
        ]);

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => 'dataway',
            'provider_product_code' => 'mtn-plan-42',
            'provider_cost_minor' => 27000,
            'network' => 'mtn',
            'code' => 'dataway-data-mtn-plan-42',
            'name' => 'Reviewed MTN plan',
            'price_minor' => 32000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['purchase_approved' => true, 'provider_mappings' => ['dataway' => ['product_id' => '42']]],
        ]);

        Http::fake(['dataway.ng/*' => Http::response(['data' => []])]);

        $result = app(ProviderCatalogueImportService::class)->import('dataway', 'data');
        $product->refresh();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, VtuProduct::query()->count());
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertSame(32000, $product->price_minor);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->metadata['purchase_approved']);
    }


    public function test_sogo_catalogue_missing_providers_field_aborts_without_mutating_existing_product(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_test_key',
        ]);

        $product = VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => 'sogo',
            'provider_product_code' => 'existing-plan',
            'provider_cost_minor' => 27000,
            'network' => 'mtn',
            'code' => 'sogo-data-existing-plan',
            'name' => 'Reviewed plan',
            'price_minor' => 32000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['purchase_approved' => false],
        ]);

        Http::fake([
            'sandbox.sogo.africa/v1/bills/catalog*' => Http::response([
                'data' => ['data' => ['message' => 'success but providers omitted']],
            ]),
        ]);

        try {
            app(ProviderCatalogueImportService::class)->import('sogo', 'data');
            $this->fail('Missing Sogo providers must not be treated as an empty catalogue.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid bill catalogue', strtolower($exception->getMessage()));
        }

        $product->refresh();
        $this->assertSame(1, VtuProduct::query()->count());
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertSame(32000, $product->price_minor);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
    }

    public function test_gsubz_catalogue_missing_data_field_aborts_without_creating_products(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz_test_key',
        ]);

        Http::fake([
            'api.gsubz.com/*' => Http::response(['message' => 'success but data omitted']),
        ]);

        try {
            app(ProviderCatalogueImportService::class)->import('gsubz', 'data');
            $this->fail('Missing GSUBZ data must not be treated as an empty catalogue.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid service catalogue', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, VtuProduct::query()->count());
    }

    public function test_dataway_nested_product_response_missing_data_field_aborts_without_creating_products(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway_test_key',
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '/products/')) {
                return Http::response(['message' => 'success but product data omitted']);
            }

            return Http::response([
                'data' => [[
                    'name' => 'MTN Data',
                    'slug' => 'mtn-data',
                    'category' => 'data',
                ]],
            ]);
        });

        try {
            app(ProviderCatalogueImportService::class)->import('dataway', 'data');
            $this->fail('Missing nested Dataway product data must abort the import.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid product catalogue', strtolower($exception->getMessage()));
        }

        $this->assertSame(0, VtuProduct::query()->count());
    }


    public function test_sogo_explicit_empty_provider_list_preserves_existing_product_and_pricing(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_test_key',
        ]);
        $product = VtuProduct::query()->create([
            'service_type' => 'data', 'provider_key' => 'sogo', 'provider_product_code' => 'mtn-plan',
            'provider_cost_minor' => 27000, 'network' => 'mtn', 'code' => 'sogo-data-mtn-plan',
            'name' => 'Reviewed plan', 'price_minor' => 32000, 'currency' => 'NGN',
            'is_active' => true, 'metadata' => ['purchase_approved' => true],
        ]);
        Http::fake(['sandbox.sogo.africa/v1/bills/catalog*' => Http::response([
            'data' => ['data' => ['providers' => []]],
        ])]);

        $result = app(ProviderCatalogueImportService::class)->import('sogo', 'data');
        $product->refresh();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, VtuProduct::query()->count());
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertSame(32000, $product->price_minor);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->metadata['purchase_approved']);
    }

    public function test_gsubz_explicit_empty_catalogue_preserves_existing_product_and_flags(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz_test_key',
        ]);
        $product = VtuProduct::query()->create([
            'service_type' => 'data', 'provider_key' => 'gsubz', 'provider_product_code' => 'mtn-plan',
            'provider_cost_minor' => 27000, 'network' => 'mtn', 'code' => 'gsubz-data-mtn-plan',
            'name' => 'Reviewed plan', 'price_minor' => 32000, 'currency' => 'NGN',
            'is_active' => true, 'metadata' => ['purchase_approved' => true],
        ]);
        Http::fake(['api.gsubz.com/api/category/*' => Http::response(['data' => []])]);

        $result = app(ProviderCatalogueImportService::class)->import('gsubz', 'data');
        $product->refresh();

        $this->assertSame(0, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(1, VtuProduct::query()->count());
        $this->assertSame(27000, $product->provider_cost_minor);
        $this->assertSame(32000, $product->price_minor);
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->metadata['purchase_approved']);
    }

    public function test_sogo_malformed_nested_plan_response_aborts_without_partial_import(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_test_key',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/bills/catalog')) {
                return Http::response(['data' => ['data' => ['providers' => [
                    ['slug' => 'mtn', 'name' => 'MTN'],
                ]]]]);
            }
            if (str_contains($request->url(), '/bills/data-plans')) {
                return Http::response(['message' => 'success but plan data omitted']);
            }
            return Http::response(['message' => 'unexpected request'], 500);
        });

        try {
            app(ProviderCatalogueImportService::class)->import('sogo', 'data');
            $this->fail('Malformed nested Sogo plan response must abort the import.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid data plan catalogue', strtolower($exception->getMessage()));
        }
        $this->assertSame(0, VtuProduct::query()->count());
    }

    public function test_gsubz_malformed_nested_plan_response_aborts_without_partial_import(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz_test_key',
        ]);
        Http::fake([
            'api.gsubz.com/api/category/*' => Http::response(['data' => [
                ['serviceID' => 'mtn-data', 'name' => 'MTN Data'],
            ]]),
            'api.gsubz.com/api/plans/*' => Http::response(['message' => 'success but plan data omitted']),
        ]);

        try {
            app(ProviderCatalogueImportService::class)->import('gsubz', 'data');
            $this->fail('Malformed nested GSUBZ plan response must abort the import.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('invalid plan catalogue', strtolower($exception->getMessage()));
        }
        $this->assertSame(0, VtuProduct::query()->count());
    }

    public function test_gsubz_timeout_after_a_partial_plan_response_does_not_import_partial_products(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'gsubz_test_key',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/api/category/')) {
                return Http::response(['data' => [
                    ['serviceID' => 'mtn-data', 'name' => 'MTN Data'],
                    ['serviceID' => 'airtel-data', 'name' => 'Airtel Data'],
                ]]);
            }
            if (str_contains($request->url(), '/api/plans/') && str_contains($request->url(), 'service=mtn-data')) {
                return Http::response(['data' => [[
                    'value' => 'mtn-sme-1gb', 'name' => 'MTN SME 1GB', 'api_price' => 290,
                ]]]);
            }
            if (str_contains($request->url(), '/api/plans/') && str_contains($request->url(), 'service=airtel-data')) {
                throw new ConnectionException('Connection timed out.');
            }
            return Http::response(['message' => 'unexpected request'], 500);
        });

        try {
            app(ProviderCatalogueImportService::class)->import('gsubz', 'data');
            $this->fail('A GSUBZ timeout must abort the entire partial snapshot.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
        $this->assertSame(0, VtuProduct::query()->count());
    }

    public function test_dataway_timeout_after_a_partial_biller_response_does_not_import_partial_products(): void
    {
        config([
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'dataway_test_key',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/products/')) {
                throw new ConnectionException('Connection timed out.');
            }
            if (str_contains($request->url(), '/billers/')) {
                return Http::response(['data' => [
                    [
                        'name' => 'MTN Data', 'slug' => 'mtn-data', 'category' => 'data',
                        'plans' => [[
                            'id' => 41, 'name' => 'MTN SME 1GB', 'effective_total' => 275,
                            'validity_days' => 30, 'benefit_type' => 'SME',
                        ]],
                    ],
                    ['name' => 'Airtel Data', 'slug' => 'airtel-data', 'category' => 'data'],
                ]]);
            }
            return Http::response(['message' => 'unexpected request'], 500);
        });

        try {
            app(ProviderCatalogueImportService::class)->import('dataway', 'data');
            $this->fail('A Dataway timeout must abort the entire partial snapshot.');
        } catch (ConnectionException $exception) {
            $this->assertStringContainsString('timed out', $exception->getMessage());
        }
        $this->assertSame(0, VtuProduct::query()->count());
    }

}
