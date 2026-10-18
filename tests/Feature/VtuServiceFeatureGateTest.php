<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VtuProduct;
use App\Models\PlatformFeatureControl;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class VtuServiceFeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_airtime_data_toggle_does_not_hide_electricity_catalogue(): void
    {
        $user = User::query()->create([
            'name' => 'Feature Gate Customer',
            'email' => 'feature-gate-customer@example.test',
            'password' => 'not-a-real-password',
        ]);
        app(IntegrationSettings::class)->set('service.airtime_data.enabled', '0', $user->id);

        VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'code' => 'feature-gate-electricity',
            'name' => 'Ikeja Electric',
            'price_minor' => 10000,
            'face_value_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['purchase_supported' => true, 'provider_service_id' => 'ikeja-electric'],
        ]);

        $this->actingAs($user)->getJson('/wallet/vtu/products?service_type=airtime')->assertStatus(503);
        $this->actingAs($user)->getJson('/wallet/vtu/products?service_type=electricity-bill')
            ->assertOk()
            ->assertJsonPath('data.0.code', 'feature-gate-electricity');
    }

    public function test_missing_airtime_data_feature_control_fails_closed_for_catalogue_and_purchase(): void
    {
        $user = User::query()->create([
            'name' => 'Missing Airtime Feature Customer',
            'email' => 'missing-airtime-feature@example.test',
            'password' => 'not-a-real-password',
        ]);
        app(IntegrationSettings::class)->set('service.airtime_data.enabled', '1', $user->id);
        PlatformFeatureControl::query()->where('feature_key', 'service.airtime_data')->delete();
        VtuProduct::query()->create([
            'service_type' => 'airtime',
            'network' => 'mtn',
            'code' => 'missing-airtime-feature-product',
            'name' => 'MTN Airtime',
            'price_minor' => 10000,
            'face_value_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['purchase_supported' => true, 'provider_service_id' => 'mtn'],
        ]);

        $this->actingAs($user)->getJson('/wallet/vtu/products?service_type=airtime')->assertStatus(503);
        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'missing-airtime-feature-product',
            'recipient' => '08012345678',
            'idempotency_key' => 'missing-airtime-feature-control',
            'amount' => 100,
        ])->assertStatus(503)->assertJsonPath('message', 'Airtime and data purchases are temporarily unavailable.');

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_unimplemented_electricity_feature_blocks_purchase_even_if_product_metadata_allows_it(): void
    {
        $user = User::query()->create([
            'name' => 'Provider Gate Customer',
            'email' => 'provider-gate-customer@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill(['transaction_pin_hash' => Hash::make('4928')])->save();
        app(IntegrationSettings::class)->set('service.airtime_data.enabled', '0', $user->id);

        VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'code' => 'provider-gated-electricity',
            'name' => 'Ikeja Electric',
            'price_minor' => 10000,
            'face_value_minor' => 10000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['purchase_supported' => true, 'provider_service_id' => 'ikeja-electric'],
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/orders', [
            'product_code' => 'provider-gated-electricity',
            'recipient' => '12345678901',
            'idempotency_key' => 'provider-gate-no-purchase',
            'transaction_pin' => '4928',
            'meter_type' => 'prepaid',
            'contact_phone' => '08012345678',
            'amount' => 100,
        ])->assertStatus(422)
          ->assertJsonPath('message', 'This service is not yet enabled for customer purchases.');

        $this->assertDatabaseCount('vtu_orders', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }
}
