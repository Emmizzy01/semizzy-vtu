<?php

namespace Tests\Feature;

use App\Models\PlatformAddon;
use App\Models\User;
use App\Services\Addons\PlatformAddonRegistry;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformAddonRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function addon(string $key, array $overrides = []): PlatformAddon
    {
        return PlatformAddon::query()->create(array_merge([
            'addon_key' => $key,
            'name' => ucfirst(str_replace('.', ' ', $key)),
            'version' => '1.0.0',
            'minimum_core_version' => '1.0.0',
            'dependencies' => [],
            'status' => 'disabled',
            'is_core_owned' => false,
            'compatibility_status' => 'unchecked',
        ], $overrides));
    }

    public function test_addon_cannot_be_enabled_until_declared_dependencies_are_enabled(): void
    {
        $registry = app(PlatformAddonRegistry::class);
        $this->addon('service.base', ['status' => 'disabled']);
        $dependent = $this->addon('service.child', ['dependencies' => ['service.base']]);
        $actor = User::query()->create(['name' => 'Admin', 'email' => 'addon-admin@example.test', 'password' => 'CorrectPassword1234']);

        try {
            $registry->setEnabled($dependent, true, $actor->id);
            $this->fail('An add-on with a disabled dependency should not be enabled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $registry->setEnabled(PlatformAddon::query()->where('addon_key', 'service.base')->firstOrFail(), true, $actor->id);
        $enabled = $registry->setEnabled($dependent->fresh(), true, $actor->id);
        $this->assertSame('enabled', $enabled->status);
        $this->assertSame('compatible', $enabled->compatibility_status);
    }

    public function test_enabled_dependency_cannot_be_disabled_while_an_enabled_dependent_uses_it(): void
    {
        $registry = app(PlatformAddonRegistry::class);
        $actor = User::query()->create(['name' => 'Admin', 'email' => 'addon-admin-two@example.test', 'password' => 'CorrectPassword1234']);
        $base = $this->addon('service.base', ['status' => 'enabled']);
        $child = $this->addon('service.child', ['dependencies' => ['service.base'], 'status' => 'enabled']);

        try {
            $registry->setEnabled($base, false, $actor->id);
            $this->fail('A dependency used by an enabled add-on should not be disabled.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $registry->setEnabled($child, false, $actor->id);
        $this->assertSame('disabled', $registry->setEnabled($base->fresh(), false, $actor->id)->status);
    }

    public function test_manifest_registration_rejects_core_owned_capabilities_and_dependency_cycles(): void
    {
        $registry = app(PlatformAddonRegistry::class);
        $actor = User::query()->create(['name' => 'Admin', 'email' => 'addon-admin-three@example.test', 'password' => 'CorrectPassword1234']);

        try {
            $registry->register([
                'addon_key' => 'core.wallet', 'name' => 'Duplicate wallet', 'version' => '1.0.0',
                'minimum_core_version' => '1.0.0', 'dependencies' => [],
            ], $actor->id);
            $this->fail('Core wallet ownership must not be duplicated by an add-on.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('addon_key', $exception->errors());
        }

        $this->addon('service.first', ['dependencies' => ['service.second']]);
        try {
            $registry->register([
                'addon_key' => 'service.second', 'name' => 'Second service', 'version' => '1.0.0',
                'minimum_core_version' => '1.0.0', 'dependencies' => ['service.first'],
            ], $actor->id);
            $this->fail('Dependency cycles must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dependencies', $exception->errors());
        }
    }

    public function test_addon_is_blocked_when_its_minimum_core_version_is_newer_than_current_core(): void
    {
        app(IntegrationSettings::class)->set('platform.core_version', '1.2.0', null);
        $addon = $this->addon('service.future', ['minimum_core_version' => '2.0.0']);
        $assessment = app(PlatformAddonRegistry::class)->assess($addon);

        $this->assertSame('incompatible', $assessment['status']);
        $this->assertStringContainsString('Requires core 2.0.0', $assessment['message']);
    }
}
