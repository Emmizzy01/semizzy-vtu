<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Models\VtuProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VtuBillerVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['electricity', 'tv'] as $service) {
            PlatformFeatureControl::query()->updateOrCreate(
                ['feature_key' => 'service.'.$service],
                ['is_enabled' => true, 'is_implemented' => true],
            );
            PlatformAddon::query()->updateOrCreate(
                ['addon_key' => 'service.'.$service],
                ['name' => ucfirst($service), 'version' => '1.0.0', 'minimum_core_version' => '1.0.0', 'status' => 'enabled', 'is_core_owned' => false, 'compatibility_status' => 'compatible'],
            );
        }

        config([
            'services.vtu.vtpass.base_url' => 'https://sandbox.vtpass.com/api',
            'services.vtu.vtpass.api_key' => 'sandbox-api-key',
            'services.vtu.vtpass.secret_key' => 'sandbox-secret-key',
        ]);
    }

    public function test_authenticated_user_can_verify_an_electricity_meter_without_creating_an_order(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => ['Customer_Name' => 'Meter Holder', 'Address' => '21a New Road Avenue'],
        ], 200));

        $user = User::query()->create([
            'name' => 'Verification User',
            'email' => 'verification-user@example.test',
            'password' => 'not-a-real-password',
        ]);
        VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'code' => 'ikeja-meter-check',
            'name' => 'Ikeja Electric',
            'price_minor' => 0,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['catalog_source' => 'vtpass', 'provider_service_id' => 'ikeja-electric'],
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'ikeja-meter-check',
            'biller_code' => '12345678901',
            'meter_type' => 'prepaid',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.customer_name', 'Meter Holder')
            ->assertJsonPath('data.customer_address', '21a New Road Avenue');

        $this->assertDatabaseCount('vtu_orders', 0);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/merchant-verify')
            && $request['serviceID'] === 'ikeja-electric'
            && $request['type'] === 'prepaid');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
    }

    public function test_electricity_meter_with_provider_can_vend_no_is_not_verified(): void
    {
        Http::fake(fn () => Http::response([
            'code' => '000',
            'content' => [
                'Customer_Name' => 'Meter Holder',
                'WrongBillersCode' => false,
                'Can_Vend' => 'no',
            ],
        ], 200));

        $user = User::query()->create([
            'name' => 'Cannot Vend User',
            'email' => 'cannot-vend@example.test',
            'password' => 'not-a-real-password',
        ]);
        VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'eko-electric',
            'code' => 'eko-meter-cannot-vend',
            'name' => 'Eko Electric',
            'price_minor' => 0,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['catalog_source' => 'vtpass', 'provider_service_id' => 'eko-electric'],
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'eko-meter-cannot-vend',
            'biller_code' => '1111111111111',
            'meter_type' => 'prepaid',
        ])->assertOk()
            ->assertJsonPath('data.status', 'unverified');

        $this->assertDatabaseCount('vtu_orders', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
    }

    public function test_tv_smartcard_verification_success_and_failure_never_create_purchase_orders(): void
    {
        $user = User::query()->create([
            'name' => 'TV Verification User',
            'email' => 'tv-verification-user@example.test',
            'password' => 'not-a-real-password',
        ]);
        VtuProduct::query()->create([
            'service_type' => 'tv-subscription',
            'network' => 'dstv',
            'code' => 'dstv-smartcard-check',
            'name' => 'DStv Subscription',
            'price_minor' => 500000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['catalog_source' => 'vtpass', 'provider_service_id' => 'dstv'],
        ]);

        Http::fake([
            'https://sandbox.vtpass.com/api/merchant-verify' => Http::sequence()
                ->push(['code' => '000', 'content' => ['Customer_Name' => 'TV Customer']], 200)
                ->push(['code' => '099', 'response_description' => 'Invalid smartcard'], 200)
                ->push(['code' => '000', 'content' => ['Customer_Name' => 'Wrong Customer', 'WrongBillersCode' => true]], 200),
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'dstv-smartcard-check',
            'biller_code' => '123456789012',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.customer_name', 'TV Customer');

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'dstv-smartcard-check',
            'biller_code' => '999999999999',
        ])->assertOk()
            ->assertJsonPath('data.status', 'unverified');

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'dstv-smartcard-check',
            'biller_code' => '123456789013',
        ])->assertOk()
            ->assertJsonPath('data.status', 'unverified');

        $this->assertDatabaseCount('vtu_orders', 0);
        Http::assertSentCount(3);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
    }

    public function test_disabled_service_addon_blocks_biller_verification_before_provider_call(): void
    {
        Http::fake();
        $user = User::query()->create([
            'name' => 'Disabled Biller User',
            'email' => 'disabled-biller@example.test',
            'password' => 'not-a-real-password',
        ]);
        $product = VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'code' => 'disabled-addon-meter-check',
            'name' => 'Ikeja Electric',
            'price_minor' => 0,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['catalog_source' => 'vtpass', 'provider_service_id' => 'ikeja-electric'],
        ]);
        PlatformFeatureControl::query()->where('feature_key', 'service.electricity')->update([
            'is_enabled' => false,
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => $product->code,
            'biller_code' => '12345678901',
            'meter_type' => 'prepaid',
        ])->assertStatus(503)
            ->assertJsonPath('reason', 'feature_disabled');

        Http::assertNothingSent();
        $this->assertDatabaseCount('vtu_orders', 0);
    }

    public function test_verification_never_falls_back_to_vtpass_for_a_different_active_provider(): void
    {
        Http::fake();
        $user = User::query()->create([
            'name' => 'Other Provider User',
            'email' => 'other-provider@example.test',
            'password' => 'not-a-real-password',
        ]);
        VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'example-electric',
            'code' => 'other-provider-meter',
            'name' => 'Example Electric',
            'price_minor' => 0,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['catalog_source' => 'other-provider', 'provider_service_id' => 'example-electric'],
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'other-provider-meter',
            'biller_code' => '12345678901',
            'meter_type' => 'prepaid',
        ])->assertStatus(503)
            ->assertJsonPath('data.status', 'unknown');

        $this->assertDatabaseCount('vtu_orders', 0);
        Http::assertNothingSent();
    }

    public function test_electricity_verification_requires_meter_type_and_rejects_non_biller_products(): void
    {
        Http::fake();
        $user = User::query()->create([
            'name' => 'Verification User Two',
            'email' => 'verification-user-two@example.test',
            'password' => 'not-a-real-password',
        ]);
        VtuProduct::query()->create([
            'service_type' => 'electricity-bill',
            'network' => 'ikeja-electric',
            'code' => 'ikeja-meter-check-2',
            'name' => 'Ikeja Electric',
            'price_minor' => 0,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => ['provider_service_id' => 'ikeja-electric'],
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'ikeja-meter-check-2',
            'biller_code' => '12345678901',
        ])->assertUnprocessable();

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'missing-service',
            'biller_code' => '12345678901',
            'meter_type' => 'prepaid',
        ])->assertUnprocessable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('vtu_orders', 0);
    }
}
