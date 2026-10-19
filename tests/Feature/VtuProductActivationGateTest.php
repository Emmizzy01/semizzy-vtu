<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\VtuProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VtuProductActivationGateTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithCataloguePermission(): User
    {
        $admin = User::factory()->create();

        UserPermission::query()->create([
            'user_id' => $admin->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => $admin->id,
        ]);

        return $admin;
    }

    private function product(string $provider, array $metadata = []): VtuProduct
    {
        return VtuProduct::query()->create([
            'service_type' => 'data',
            'provider_key' => $provider,
            'provider_product_code' => 'mtn-sme-1gb',
            'provider_cost_minor' => 29000,
            'network' => 'mtn',
            'data_type' => 'SME',
            'validity' => '30 days',
            'code' => $provider.'-data-mtn-sme-1gb',
            'name' => 'MTN SME 1GB',
            'price_minor' => 35000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => $metadata,
        ]);
    }

    private function activationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'MTN SME 1GB',
            'price_minor' => 35000,
            'is_active' => true,
            'purchase_approved' => true,
            'reason' => 'Approve verified provider product',
        ], $overrides);
    }

    public function test_sogo_product_cannot_be_activated_with_sandbox_endpoint(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://sandbox.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'test-key',
        ]);

        $admin = $this->adminWithCataloguePermission();
        $product = $this->product('sogo', [
            'purchase_approved' => false,
            'provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'variation_code' => 'mtn-sme-1gb'],
            ],
        ]);

        $this->actingAs($admin)
            ->put('/admin/vtu-products/'.$product->id, $this->activationPayload())
            ->assertUnprocessable();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertFalse($product->fresh()->metadata['purchase_approved']);
    }

    public function test_gsubz_data_product_cannot_be_activated_without_plan_mapping(): void
    {
        config([
            'services.vtu.gsubz.base_url' => 'https://api.gsubz.com',
            'services.vtu.gsubz.api_key' => 'test-key',
        ]);

        $admin = $this->adminWithCataloguePermission();
        $product = $this->product('gsubz', [
            'purchase_approved' => false,
            'provider_mappings' => ['gsubz' => ['network' => 'mtn']],
        ]);

        $this->actingAs($admin)
            ->put('/admin/vtu-products/'.$product->id, $this->activationPayload())
            ->assertUnprocessable();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertFalse($product->fresh()->metadata['purchase_approved']);
    }

    public function test_dataway_product_cannot_be_activated_even_when_approval_is_requested(): void
    {
        $admin = $this->adminWithCataloguePermission();
        $product = $this->product('dataway', [
            'purchase_approved' => false,
            'provider_mappings' => ['dataway' => ['network' => 'mtn', 'product_id' => '42']],
        ]);

        $this->actingAs($admin)
            ->put('/admin/vtu-products/'.$product->id, $this->activationPayload())
            ->assertUnprocessable();

        $this->assertFalse($product->fresh()->is_active);
        $this->assertFalse($product->fresh()->metadata['purchase_approved']);
    }

    public function test_approved_sogo_product_with_live_endpoint_and_complete_mapping_can_be_activated(): void
    {
        config([
            'services.vtu.sogo.base_url' => 'https://api.sogo.africa/v1',
            'services.vtu.sogo.api_key' => 'test-key',
        ]);

        $admin = $this->adminWithCataloguePermission();
        $product = $this->product('sogo', [
            'purchase_approved' => false,
            'provider_mappings' => [
                'sogo' => ['network' => 'mtn', 'variation_code' => 'mtn-sme-1gb'],
            ],
        ]);

        $this->actingAs($admin)
            ->put('/admin/vtu-products/'.$product->id, $this->activationPayload())
            ->assertRedirect();

        $product->refresh();
        $this->assertTrue($product->is_active);
        $this->assertTrue($product->metadata['purchase_approved']);
    }
}
