<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VtpassCatalogueFailureSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_provider_variation_fetch_does_not_partially_apply_catalogue_or_change_existing_prices(): void
    {
        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'test-key',
            'services.vtu.vtpass.secret_key' => 'test-secret',
            'services.vtu.airtime_denominations' => [100],
        ]);

        $staff = User::query()->create([
            'name' => 'Catalogue Safety Staff',
            'email' => 'catalogue-safety@example.test',
            'password' => 'not-a-real-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $existing = VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'existing-mtn-plan',
            'name' => 'Operator controlled plan',
            'price_minor' => 77700,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['provider_variation_code' => 'operator-code', 'operator_note' => 'preserve'],
        ]);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/service-categories')) {
                return Http::response(['content' => [
                    ['identifier' => 'airtime', 'name' => 'Airtime'],
                    ['identifier' => 'data', 'name' => 'Data'],
                ]], 200);
            }

            if (str_contains($request->url(), '/services?')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

                return Http::response(['content' => ($query['identifier'] ?? '') === 'airtime'
                    ? [['serviceID' => 'mtn']]
                    : [['serviceID' => 'mtn-data', 'name' => 'MTN Data']]], 200);
            }

            if (str_contains($request->url(), '/service-variations?')) {
                return Http::response(['message' => 'temporary provider error'], 503);
            }

            return Http::response([], 404);
        });

        $this->actingAs($staff)
            ->post('/admin/vtu-products/sync')
            ->assertRedirect()
            ->assertSessionHasErrors('catalog_sync');

        $this->assertDatabaseMissing('vtu_products', ['code' => 'vtpass-airtime-mtn-100']);
        $existing->refresh();
        $this->assertSame(77700, (int) $existing->price_minor);
        $this->assertTrue($existing->is_active);
        $this->assertSame('operator-code', $existing->metadata['provider_variation_code']);
        $this->assertSame('preserve', $existing->metadata['operator_note']);
        $this->assertDatabaseMissing('platform_setting_audits', ['setting_key' => 'vtu_catalogue.synced.vtpass']);
    }

    public function test_vtpass_repeated_catalogue_sync_is_idempotent_and_keeps_products_inactive(): void
    {
        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'test-key',
            'services.vtu.vtpass.secret_key' => 'test-secret',
            'services.vtu.airtime_denominations' => [100],
        ]);

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/service-categories') {
                return Http::response(['content' => [
                    ['identifier' => 'airtime', 'name' => 'Airtime'],
                    ['identifier' => 'data', 'name' => 'Data'],
                ]]);
            }
            if ($path === '/api/services') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                return Http::response(['content' => ($query['identifier'] ?? '') === 'airtime'
                    ? [['serviceID' => 'mtn', 'name' => 'MTN Airtime']]
                    : [['serviceID' => 'mtn-data', 'name' => 'MTN Data']]]);
            }
            if ($path === '/api/service-variations') {
                return Http::response(['content' => ['variations' => [[
                    'variation_code' => 'mtn-1gb-30days',
                    'name' => 'MTN 1GB 30 days',
                    'variation_amount' => 300,
                ]]]]);
            }
            return Http::response(['message' => 'unexpected request'], 404);
        });

        $catalogue = app(\\App\\Services\\Vtu\\VtpassCatalogService::class);
        $first = $catalogue->fetchProducts();
        $firstResult = $catalogue->applyProducts($first);
        $second = $catalogue->fetchProducts();
        $secondResult = $catalogue->applyProducts($second);

        $this->assertSame(2, $firstResult['created']);
        $this->assertSame(0, $firstResult['updated']);
        $this->assertSame(0, $secondResult['created']);
        $this->assertSame(2, $secondResult['updated']);
        $this->assertSame(2, VtuProduct::query()->count());
        $this->assertSame(1, VtuProduct::query()->where('service_type', 'data')->count());
        $this->assertSame(1, VtuProduct::query()->where('service_type', 'airtime')->count());
        $this->assertSame(30000, VtuProduct::query()->where('service_type', 'data')->value('price_minor'));
        $this->assertDatabaseHas('vtu_products', ['is_active' => 0]);
        $this->assertFalse((bool) VtuProduct::query()->where('service_type', 'data')->firstOrFail()->metadata['purchase_approved']);
    }

    public function test_catalogue_import_includes_electricity_services_and_tv_bouquet_variations_inactive(): void
    {
        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'test-key',
            'services.vtu.vtpass.secret_key' => 'test-secret',
            'services.vtu.airtime_denominations' => [100],
        ]);

        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/service-categories') {
                return Http::response(['response_description' => '000', 'content' => [
                    ['identifier' => 'airtime', 'name' => 'Airtime Recharge'],
                    ['identifier' => 'data', 'name' => 'Data Services'],
                    ['identifier' => 'electricity-bill', 'name' => 'Electricity Bill'],
                    ['identifier' => 'tv-subscription', 'name' => 'TV Subscription'],
                ]], 200);
            }

            if ($path === '/api/services') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $services = match ($query['identifier'] ?? '') {
                    'airtime' => [['serviceID' => 'mtn', 'name' => 'MTN Airtime']],
                    'data' => [['serviceID' => 'mtn-data', 'name' => 'MTN Data']],
                    'electricity-bill' => [
                        ['serviceID' => 'ikeja-electric', 'name' => 'Ikeja Electric', 'minimium_amount' => '100', 'maximum_amount' => '50000'],
                        ['serviceID' => 'eko-electric', 'name' => 'Eko Electric', 'minimium_amount' => '100', 'maximum_amount' => '50000'],
                    ],
                    'tv-subscription' => [
                        ['serviceID' => 'dstv', 'name' => 'DStv Payment'],
                        ['serviceID' => 'gotv', 'name' => 'GOtv Payment'],
                        ['serviceID' => 'startimes', 'name' => 'Startimes Payment'],
                    ],
                    default => [],
                };

                return Http::response(['response_description' => '000', 'content' => $services], 200);
            }

            if ($path === '/api/service-variations') {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                if (($query['serviceID'] ?? '') === 'mtn-data') {
                    return Http::response(['response_description' => '000', 'content' => ['variations' => [
                        ['variation_code' => 'mtn-1gb', 'name' => '1GB 30 Days', 'variation_amount' => '500.00'],
                    ]]], 200);
                }

                $variations = match ($query['serviceID'] ?? '') {
                    'dstv' => [['variation_code' => 'dstv-padi', 'name' => 'DStv Padi', 'variation_amount' => '4400.00', 'fixedPrice' => 'Yes']],
                    'gotv' => [['variation_code' => 'gotv-lite', 'name' => 'GOtv Lite', 'variation_amount' => '900.00', 'fixedPrice' => 'Yes']],
                    'startimes' => [['variation_code' => 'nova', 'name' => 'Startimes Nova', 'variation_amount' => '1200.00', 'fixedPrice' => 'Yes']],
                    default => [],
                };

                return Http::response(['response_description' => '000', 'content' => ['variations' => $variations]], 200);
            }

            return Http::response([], 404);
        });

        $catalog = app(\App\Services\Vtu\VtpassCatalogService::class);
        $products = $catalog->fetchProducts();

        $electricity = array_values(array_filter($products, fn (array $product): bool => $product['service_type'] === 'electricity-bill'));
        $tv = array_values(array_filter($products, fn (array $product): bool => $product['service_type'] === 'tv-subscription'));

        $this->assertSame(['eko-electric', 'ikeja-electric'], collect($electricity)->map(fn (array $product) => $product['metadata']['provider_service_id'])->sort()->values()->all());
        $this->assertCount(3, $tv);
        $this->assertEqualsCanonicalizing(['dstv-padi', 'gotv-lite', 'nova'], array_column(array_column($tv, 'metadata'), 'provider_variation_code'));
        foreach (array_merge($electricity, $tv) as $product) {
            $this->assertFalse($product['is_active']);
            $this->assertFalse($product['metadata']['purchase_approved']);
            $this->assertFalse($product['metadata']['purchase_supported']);
            $this->assertSame('vtpass', $product['metadata']['catalog_source']);
        }

        $result = $catalog->applyProducts(array_merge($electricity, $tv));
        $this->assertSame(5, $result['created']);
        $this->assertDatabaseCount('vtu_products', 5);
        $this->assertSame(0, VtuProduct::query()->where('is_active', true)->count());
    }

}
