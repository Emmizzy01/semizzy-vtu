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
}
