<?php

namespace Tests\Feature;

use App\Models\BusinessWorkspace;
use App\Models\BusinessWorkspaceMember;
use App\Models\BusinessWorkspaceStorefront;
use App\Models\User;
use App\Models\VtuProduct;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessWorkspaceStorefrontTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $email): User
    {
        return User::query()->create([
            'name' => 'Storefront Test User',
            'email' => $email,
            'password' => 'CorrectPassword1234',
        ]);
    }

    private function workspace(User $owner, string $slug, string $type = 'reseller'): BusinessWorkspace
    {
        return BusinessWorkspace::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Reseller Store',
            'slug' => $slug,
            'type' => $type,
            'status' => 'active',
        ]);
    }

    private function product(string $code = 'store-data-plan', array $metadata = []): VtuProduct
    {
        return VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => $code,
            'name' => 'MTN 1GB 7 Days',
            'price_minor' => 50000,
            'face_value_minor' => null,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => $metadata,
        ]);
    }

    public function test_reseller_owner_can_configure_and_publish_storefront_with_active_approved_catalogue(): void
    {
        $owner = $this->account('storefront-owner@example.test');
        $workspace = $this->workspace($owner, 'reseller-store');
        $this->product();

        $this->actingAs($owner)->get(route('workspaces.storefront.edit', $workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Workspaces/StorefrontEdit')->where('storefront.status', 'draft'));

        $storefront = BusinessWorkspaceStorefront::query()->where('business_workspace_id', $workspace->id)->firstOrFail();
        $this->actingAs($owner)->put(route('workspaces.storefront.update', $workspace), [
            'slug' => 'reseller-store',
            'display_name' => 'My Data Store',
            'tagline' => 'Reliable airtime and data',
            'description' => 'Welcome to my reseller store.',
            'status' => 'published',
            'enabled_services' => ['data'],
            'accent_color' => '#087443',
        ])->assertSessionHasNoErrors();

        $this->assertSame('published', $storefront->fresh()->status);
        $this->get(route('storefronts.show', ['storefront' => 'reseller-store']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Storefronts/Show')
                ->where('storefront.displayName', 'My Data Store')
                ->where('servicesAvailable', true)
                ->has('products', 1)
                ->where('products.0.code', 'store-data-plan')
                ->where('products.0.priceMinor', 50000));
    }

    public function test_public_storefront_hides_draft_inactive_and_unapproved_vtpass_products(): void
    {
        $owner = $this->account('storefront-filter-owner@example.test');
        $workspace = $this->workspace($owner, 'storefront-filter');
        $this->product('approved-data');
        $this->product('unapproved-vtpass-data', ['catalog_source' => 'vtpass', 'purchase_approved' => false]);
        VtuProduct::query()->create([
            'service_type' => 'data', 'network' => 'glo', 'code' => 'inactive-data',
            'name' => 'Inactive Data', 'price_minor' => 10000, 'currency' => 'NGN',
            'is_active' => false, 'metadata' => [],
        ]);
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id, 'slug' => 'storefront-filter',
            'display_name' => 'Filtered Store', 'status' => 'published',
            'enabled_services' => ['data'], 'accent_color' => '#087443',
        ]);

        $this->get(route('storefronts.show', ['storefront' => $storefront->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.code', 'approved-data'));

        $storefront->update(['status' => 'draft']);
        $this->get(route('storefronts.show', ['storefront' => $storefront->slug]))->assertNotFound();
    }

    public function test_storefront_cannot_be_published_without_active_approved_products_or_when_service_is_disabled(): void
    {
        $owner = $this->account('storefront-gate-owner@example.test');
        $workspace = $this->workspace($owner, 'storefront-gate');
        $this->actingAs($owner)->get(route('workspaces.storefront.edit', $workspace))->assertOk();

        $this->actingAs($owner)->put(route('workspaces.storefront.update', $workspace), [
            'slug' => 'storefront-gate', 'display_name' => 'Storefront Gate',
            'status' => 'published', 'enabled_services' => ['data'], 'accent_color' => '#087443',
        ])->assertSessionHasErrors('status');

        $this->product('gate-vtpass-data', ['catalog_source' => 'vtpass', 'purchase_approved' => true]);
        app(IntegrationSettings::class)->set('service.airtime_data.enabled', '0', $owner->id);
        $this->actingAs($owner)->put(route('workspaces.storefront.update', $workspace), [
            'slug' => 'storefront-gate', 'display_name' => 'Storefront Gate',
            'status' => 'published', 'enabled_services' => ['data'], 'accent_color' => '#087443',
        ])->assertSessionHasErrors('status');

        $this->assertSame('draft', $workspace->storefront()->firstOrFail()->fresh()->status);
    }

    public function test_storefront_edit_is_scoped_to_reseller_workspace_and_explicit_permission(): void
    {
        $owner = $this->account('storefront-scope-owner@example.test');
        $otherOwner = $this->account('storefront-scope-other@example.test');
        $agent = $this->account('storefront-scope-agent@example.test');
        $workspace = $this->workspace($owner, 'storefront-scope-first');
        $otherWorkspace = $this->workspace($otherOwner, 'storefront-scope-second');
        BusinessWorkspaceMember::query()->create([
            'business_workspace_id' => $workspace->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'permissions' => ['orders.view'],
            'status' => 'active',
        ]);

        $this->actingAs($otherOwner)->get(route('workspaces.storefront.edit', $workspace))->assertForbidden();
        $this->actingAs($agent)->get(route('workspaces.storefront.edit', $workspace))->assertForbidden();
        $this->actingAs($owner)->get(route('workspaces.storefront.edit', $otherWorkspace))->assertForbidden();

        $agentMembership = $workspace->memberships()->where('user_id', $agent->id)->firstOrFail();
        $agentMembership->update(['permissions' => ['store.manage']]);
        $this->actingAs($agent)->get(route('workspaces.storefront.edit', $workspace))->assertOk();

        $businessWorkspace = $this->workspace($owner, 'ordinary-business', 'business');
        $this->actingAs($owner)->get(route('workspaces.storefront.edit', $businessWorkspace))->assertNotFound();
    }

    public function test_public_storefront_is_hidden_when_workspace_is_suspended(): void
    {
        $owner = $this->account('storefront-suspended-owner@example.test');
        $workspace = $this->workspace($owner, 'storefront-suspended');
        $this->product('suspended-data');
        $storefront = BusinessWorkspaceStorefront::query()->create([
            'business_workspace_id' => $workspace->id, 'slug' => 'storefront-suspended',
            'display_name' => 'Suspended Store', 'status' => 'published',
            'enabled_services' => ['data'], 'accent_color' => '#087443',
        ]);
        $workspace->update(['status' => 'suspended']);

        $this->get(route('storefronts.show', ['storefront' => $storefront->slug]))->assertNotFound();
    }
}
