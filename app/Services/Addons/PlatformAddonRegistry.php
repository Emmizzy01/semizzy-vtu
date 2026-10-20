<?php

namespace App\Services\Addons;

use App\Models\PlatformAddon;
use App\Services\IntegrationSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlatformAddonRegistry
{
    public function __construct(private readonly IntegrationSettings $settings)
    {
    }

    public function coreVersion(): string
    {
        $version = trim((string) $this->settings->get('platform.core_version', '1.0.0'));

        return preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $version) ? $version : '1.0.0';
    }

    public function assess(PlatformAddon $addon): array
    {
        $coreVersion = $this->coreVersion();
        if (version_compare($coreVersion, (string) $addon->minimum_core_version, '<')) {
            return ['status' => 'incompatible', 'message' => "Requires core {$addon->minimum_core_version}; current core is {$coreVersion}."];
        }

        foreach (($addon->dependencies ?? []) as $dependencyKey) {
            $dependency = PlatformAddon::query()->where('addon_key', $dependencyKey)->first();
            if (!$dependency) {
                return ['status' => 'blocked', 'message' => "Missing dependency: {$dependencyKey}."];
            }
            if ($dependency->status !== 'enabled') {
                return ['status' => 'blocked', 'message' => "Dependency {$dependencyKey} must be enabled first."];
            }
        }

        return ['status' => 'compatible', 'message' => 'Core version and declared dependencies are compatible.'];
    }

    public function refreshCompatibility(): void
    {
        PlatformAddon::query()->get()->each(function (PlatformAddon $addon): void {
            // Do not let a dependent stay enabled when its dependency is missing or disabled.
            $assessment = $this->assess($addon);
            $addon->forceFill([
                'compatibility_status' => $assessment['status'],
                'compatibility_message' => $assessment['message'],
            ])->save();
        });
    }

    public function register(array $data, ?int $actorId): PlatformAddon
    {
        $key = strtolower(trim($data['addon_key']));
        if (in_array($key, ['core.auth', 'core.wallet', 'core.pricing', 'core.settings'], true)) {
            throw ValidationException::withMessages(['addon_key' => 'Core-owned capabilities cannot be registered as independent add-ons.']);
        }

        $dependencies = array_values(array_unique($data['dependencies'] ?? []));
        if (in_array($key, $dependencies, true)) {
            throw ValidationException::withMessages(['dependencies' => 'An add-on cannot depend on itself.']);
        }

        return DB::transaction(function () use ($data, $key, $dependencies, $actorId): PlatformAddon {
            $addon = PlatformAddon::query()->updateOrCreate(
                ['addon_key' => $key],
                [
                    'name' => trim($data['name']),
                    'version' => trim($data['version']),
                    'minimum_core_version' => trim($data['minimum_core_version']),
                    'description' => trim((string) ($data['description'] ?? '')) ?: null,
                    'dependencies' => $dependencies,
                    'is_core_owned' => false,
                    'updated_by_user_id' => $actorId,
                ],
            );

            $this->assertNoDependencyCycle($addon);
            $assessment = $this->assess($addon);
            $addon->forceFill([
                'compatibility_status' => $assessment['status'],
                'compatibility_message' => $assessment['message'],
            ])->save();

            return $addon->fresh();
        }, attempts: 3);
    }

    public function setEnabled(PlatformAddon $addon, bool $enabled, ?int $actorId): PlatformAddon
    {
        return DB::transaction(function () use ($addon, $enabled, $actorId): PlatformAddon {
            $locked = PlatformAddon::query()->whereKey($addon->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->is_core_owned) {
                throw ValidationException::withMessages(['status' => 'Core-owned capabilities cannot be toggled as add-ons.']);
            }

            if ($enabled) {
                $assessment = $this->assess($locked);
                if ($assessment['status'] !== 'compatible') {
                    throw ValidationException::withMessages(['status' => $assessment['message']]);
                }
                $locked->forceFill([
                    'status' => 'enabled',
                    'compatibility_status' => $assessment['status'],
                    'compatibility_message' => $assessment['message'],
                    'updated_by_user_id' => $actorId,
                ])->save();
            } else {
                $dependents = PlatformAddon::query()->where('status', 'enabled')->get()->filter(
                    fn (PlatformAddon $candidate): bool => in_array($locked->addon_key, $candidate->dependencies ?? [], true)
                );
                if ($dependents->isNotEmpty()) {
                    throw ValidationException::withMessages(['status' => 'Disable dependent add-ons first: '.$dependents->pluck('addon_key')->implode(', ')]);
                }
                $locked->forceFill(['status' => 'disabled', 'updated_by_user_id' => $actorId])->save();
            }

            $this->refreshCompatibility();

            return $locked->fresh();
        }, attempts: 3);
    }

    private function assertNoDependencyCycle(PlatformAddon $start): void
    {
        $visit = function (string $key, array $path) use (&$visit): void {
            if (in_array($key, $path, true)) {
                throw ValidationException::withMessages(['dependencies' => 'Dependency cycle detected: '.implode(' → ', [...$path, $key])]);
            }
            $addon = PlatformAddon::query()->where('addon_key', $key)->first();
            if (!$addon) return;
            foreach (($addon->dependencies ?? []) as $dependency) {
                $visit((string) $dependency, [...$path, $key]);
            }
        };

        $visit($start->addon_key, []);
    }
}
