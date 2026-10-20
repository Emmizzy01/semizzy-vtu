<?php

namespace Tests\Feature;

use App\Models\VtuProduct;
use App\Services\Vtu\ProviderCatalogueImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GsubzCatalogueIdentifierCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_catalogue_exposes_all_provider_readiness_states(): void
    {
        $admin = \\App\\Models\\User::factory()->create();
        \\App\\Models\\UserPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => $admin->id,
        ]);

        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'sogo_sk_test_catalogue',
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'ap_catalogue_test_key',
            'services.vtu.dataway.base_url' => 'https://dataway.ng/api/v1/merchant',
            'services.vtu.dataway.api_key' => 'bp_live_catalogue_test_key',
        ]);

        $this->actingAs($admin)->get('/admin/vtu-products')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/VtuProducts/Index')
                ->where('providerReadiness.sogo.configured', true)
                ->where('providerReadiness.gsubz.configured', true)
                ->where('providerReadiness.dataway.configured', true)
                ->where('providerReadiness.sogo.environment', 'Sandbox'));
    }

    public function test_nested_gsubz_service_with_plain_id_is_not_dropped_before_data_plan_import(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'ap_catalogue_test_key',
        ]);

        Http::fake([
            'api.gsubz.com/api/category/*' => Http::response([
                'data' => [
                    ['id' => 'mtn_sme', 'name' => 'MTN SME Data'],
                ],
            ]),
            'api.gsubz.com/api/plans/*' => Http::response([
                'data' => [
                    [
                        'value' => 'mtn-sme-1gb-30d',
                        'name' => 'MTN SME 1GB 30 days',
                        'api_price' => 250,
                        'validity' => '30 days',
                    ],
                ],
            ]),
        ]);

        $result = app(ProviderCatalogueImportService::class)->import('gsubz', 'data');

        $this->assertSame(1, $result['created']);
        $product = VtuProduct::query()->where('provider_key', 'gsubz')->firstOrFail();
        $this->assertSame('data', $product->service_type);
        $this->assertSame('mtn', $product->network);
        $this->assertSame('mtn_sme-mtn-sme-1gb-30d', $product->provider_product_code);
        $this->assertSame(25000, $product->provider_cost_minor);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->metadata['purchase_approved']);
        $this->assertSame('mtn_sme', $product->metadata['provider_mappings']['gsubz']['network']);
        $this->assertSame('mtn-sme-1gb-30d', $product->metadata['provider_mappings']['gsubz']['plan_code']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/category/'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/plans/')
            && str_contains($request->url(), 'service=mtn_sme'));
    }
}
