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

    public function test_provider_screen_hides_provider_identity_and_credentials_during_isolation(): void
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
            ->where('provider.isolated', true)
            ->missing('provider.vtpassApiKeyConfigured')
            ->missing('provider.vtpassSecretKeyConfigured')
            ->missing('provider.vtpassBaseUrl'));
        $response->assertDontSee('do-not-expose');
        $response->assertDontSee('VTpass');
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
