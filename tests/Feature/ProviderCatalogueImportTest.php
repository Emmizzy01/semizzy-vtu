<?php

namespace Tests\Feature;

use App\Models\VtuProduct;
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

}
