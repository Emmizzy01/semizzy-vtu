<?php

namespace Tests\Feature;

use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\User;
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

    public function test_education_feature_is_registered_disabled_and_unimplemented_by_default(): void
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->firstOrFail();

        $this->assertFalse($feature->is_enabled);
        $this->assertFalse($feature->is_implemented);
    }
}
