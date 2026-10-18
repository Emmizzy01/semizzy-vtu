<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProviderIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_provider_screen_shows_safe_status_without_exposing_credentials_during_isolation(): void
    {
        $user = User::factory()->create();
        UserPermission::query()->create([
            'user_id' => $user->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => $user->id,
        ]);
        app(IntegrationSettings::class)->set('vtu.driver', 'vtpass', $user->id);
        app(IntegrationSettings::class)->set('vtu.vtpass.api_key', 'do-not-expose', $user->id);
        app(IntegrationSettings::class)->set('vtu.vtpass.secret_key', 'do-not-expose-either', $user->id);

        $response = $this->actingAs($user)->get('/admin/api-providers');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/ApiProviders')
            ->where('providers.3.key', 'vtpass')
            ->where('providers.3.purchaseHold', true)
            ->where('providers.3.purchaseRoutingEnabled', false)
            ->missing('providers.3.api_key')
            ->missing('providers.3.secret_key')
            ->missing('providers.3.base_url')
            ->where('failoverEnabled', false));
        $response->assertDontSee('do-not-expose');
        $response->assertSee('VTpass');
        $response->assertSee('VTpass remains isolated pending the existing recovery review.');
        $response->assertDontSee('do-not-expose-either');
    }

    public function test_provider_settings_cannot_be_changed_while_isolation_is_active(): void
    {
        $user = User::factory()->create();
        UserPermission::query()->create([
            'user_id' => $user->id,
            'permission' => 'staff.permissions.manage',
            'granted_by_user_id' => $user->id,
        ]);
        app(IntegrationSettings::class)->set('vtu.driver', 'vtpass', $user->id);

        $response = $this->actingAs($user)->put('/admin/api-providers', [
            'driver' => 'configured',
            'name' => 'new-provider',
            'generic_endpoint' => 'https://provider.example/api',
            'generic_token' => 'new-token',
            'reason' => 'Attempt to change provider during isolation',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('provider');
        $this->assertSame('vtpass', app(IntegrationSettings::class)->get('vtu.driver'));
        $this->assertDatabaseMissing('integration_settings', [
            'key' => 'vtu.token',
            'value' => 'new-token',
        ]);
    }
}
