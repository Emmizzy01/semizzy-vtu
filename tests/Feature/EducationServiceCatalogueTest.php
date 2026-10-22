<?php

namespace Tests\Feature;

use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\User;
use App\Services\Addons\AddonImplementationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EducationServiceCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_education_page_fails_closed_until_feature_is_enabled_and_implemented(): void
    {
        $user = User::query()->create([
            'name' => 'Education Customer',
            'email' => 'education-customer@example.test',
            'password' => 'not-a-real-password',
        ]);

        EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin',
            'exam_body' => 'WAEC',
            'name' => 'Example PIN',
            'code' => 'waec-example-pin',
            'price_minor' => 150000,
            'is_active' => true,
            'purchase_approved' => true,
        ]);

        $this->actingAs($user)->get('/services/education')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Services/Education')
                ->where('available', false)
                ->where('purchaseEnabled', false)
                ->has('products', 0));
    }

    public function test_education_has_no_customer_purchase_route_and_purchase_operation_is_unregistered(): void
    {
        $implementation = app(AddonImplementationRegistry::class)->find('service.education');

        $this->assertNotNull($implementation);
        $this->assertContains('education-services.index', $implementation['operations']);
        $this->assertContains('education-services.delivery', $implementation['operations']);
        $this->assertNotContains('education-services.purchase', $implementation['operations']);

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'education'));

        $customerWriteRoutes = $routes
            ->filter(fn ($route) => ! str_starts_with($route->uri(), 'admin/') && ! str_starts_with($route->uri(), 'education-services'))
            ->filter(fn ($route) => array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) !== []);

        $this->assertCount(0, $customerWriteRoutes);

        $deliveryRoute = $routes->first(fn ($route) => $route->getName() === 'education-services.delivery');
        $this->assertNotNull($deliveryRoute, 'Existing PIN delivery must remain reachable for accepted orders.');
        $this->assertContains('GET', $deliveryRoute->methods());
    }

    public function test_admin_catalogue_creation_forces_product_inactive_and_unapproved(): void
    {
        $staff = User::query()->create([
            'name' => 'Education Staff',
            'email' => 'education-staff@example.test',
            'password' => 'not-a-real-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $this->actingAs($staff)->post('/admin/education-services', [
            'service_type' => 'exam_pin',
            'exam_body' => 'WAEC',
            'name' => 'Example PIN',
            'code' => 'waec-example-pin',
            'provider' => 'pending-provider',
            'provider_product_code' => 'pending-sku',
            'provider_cost_minor' => 120000,
            'price_minor' => 150000,
            'requires_candidate_validation' => true,
            'reason' => 'Add initial education catalogue product',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product = EducationServiceProduct::query()->where('code', 'waec-example-pin')->firstOrFail();
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->purchase_approved);
        $this->assertSame('pending-provider', $product->provider);
        $this->assertDatabaseHas('platform_setting_audits', [
            'setting_key' => 'education_product.created.'.$product->id,
            'actor_user_id' => $staff->id,
        ]);
    }

    public function test_admin_product_edit_updates_pricing_and_keeps_product_locked(): void
    {
        $staff = User::query()->create([
            'name' => 'Education Editor',
            'email' => 'education-editor@example.test',
            'password' => 'not-a-real-password',
        ]);
        $staff->permissions()->create(['permission' => 'staff.permissions.manage']);

        $product = EducationServiceProduct::query()->create([
            'service_type' => 'exam_pin',
            'exam_body' => 'WAEC',
            'name' => 'Old product name',
            'code' => 'waec-edit-test',
            'provider' => 'pending-provider',
            'provider_product_code' => 'old-sku',
            'provider_cost_minor' => 100000,
            'price_minor' => 120000,
            'is_active' => true,
            'purchase_approved' => true,
        ]);

        $this->actingAs($staff)->put('/admin/education-services/'.$product->id, [
            'service_type' => 'exam_pin',
            'exam_body' => 'WAEC',
            'name' => 'Updated product name',
            'code' => 'waec-edit-test',
            'provider' => 'verified-later-provider',
            'provider_product_code' => 'new-sku',
            'provider_cost_minor' => 110000,
            'price_minor' => 145000,
            'requires_candidate_validation' => true,
            'reason' => 'Update product price and provider mapping',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('Updated product name', $product->name);
        $this->assertSame(145000, $product->price_minor);
        $this->assertSame(110000, $product->provider_cost_minor);
        $this->assertFalse($product->is_active);
        $this->assertFalse($product->purchase_approved);
        $this->assertDatabaseHas('platform_setting_audits', [
            'setting_key' => 'education_product.updated.'.$product->id,
            'actor_user_id' => $staff->id,
        ]);
    }

    public function test_education_feature_is_registered_disabled_and_unimplemented_by_default(): void
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->firstOrFail();

        $this->assertFalse($feature->is_enabled);
        $this->assertFalse($feature->is_implemented);
    }
}
