<?php

namespace Tests\Feature;

use App\Models\User;
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
            'content' => ['Customer_Name' => 'Meter Holder'],
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
            'metadata' => ['provider_service_id' => 'ikeja-electric'],
        ]);

        $this->actingAs($user)->postJson('/wallet/vtu/verify-biller', [
            'product_code' => 'ikeja-meter-check',
            'biller_code' => '12345678901',
            'meter_type' => 'prepaid',
        ])->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.customer_name', 'Meter Holder');

        $this->assertDatabaseCount('vtu_orders', 0);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/merchant-verify')
            && $request['serviceID'] === 'ikeja-electric'
            && $request['type'] === 'prepaid');
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/pay'));
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
