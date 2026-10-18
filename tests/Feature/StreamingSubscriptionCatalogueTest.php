<?php

namespace Tests\Feature;

use App\Models\PlatformFeatureControl;
use App\Models\StreamingSubscriptionProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StreamingSubscriptionCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_catalogue_fails_closed_while_streaming_feature_is_disabled(): void
    {
        $user = User::query()->create(['name' => 'Streaming Customer', 'email' => 'stream-customer@example.test', 'password' => 'not-a-real-password']);
        StreamingSubscriptionProduct::query()->create([
            'platform' => 'Example Stream', 'name' => 'Monthly Plan', 'code' => 'example-monthly',
            'provider' => 'not-configured', 'provider_product_code' => 'sku-1',
            'duration' => '30 days', 'price_minor' => 500000, 'currency' => 'NGN',
            'is_active' => true, 'purchase_approved' => true,
        ]);

        $this->actingAs($user)->get('/services/streaming-subscriptions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Services/StreamingSubscriptions')
                ->where('available', false)
                ->has('products', 0));
    }

    public function test_admin_can_add_catalogue_plan_but_it_is_forced_inactive_and_unapproved(): void
    {
        $staff = User::query()->create(['name' => 'Streaming Staff', 'email' => 'stream-staff@example.test', 'password' => 'not-a-real-password']);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $this->actingAs($staff)->post('/admin/streaming-subscriptions', [
            'platform' => 'Example Stream', 'name' => 'Premium Monthly', 'code' => 'example-premium-monthly',
            'provider' => 'pending-contract', 'provider_product_code' => 'sku-premium-month',
            'duration' => '1 month', 'price_minor' => 500000,
            'reason' => 'Create initial streaming catalogue plan',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = StreamingSubscriptionProduct::query()->where('code', 'example-premium-monthly')->firstOrFail();
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->purchase_approved);
        $this->assertSame('pending-contract', $product->provider);
        $this->assertDatabaseHas('platform_setting_audits', [
            'setting_key' => 'streaming_product.created.'.$product->id,
            'actor_user_id' => $staff->id,
        ]);
    }

    public function test_streaming_feature_is_not_marked_implemented_by_catalogue_scaffolding(): void
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.streaming_subscriptions')->firstOrFail();
        $this->assertFalse($feature->is_enabled);
        $this->assertFalse($feature->is_implemented);
    }
}
