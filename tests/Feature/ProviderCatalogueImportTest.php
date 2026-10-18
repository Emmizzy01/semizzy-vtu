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
                    'plan' => 'mtn-sme-1gb',
                    'PlanName' => 'MTN SME 1GB',
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

}
