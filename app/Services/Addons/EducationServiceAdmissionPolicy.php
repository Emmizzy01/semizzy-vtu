<?php

namespace App\Services\Addons;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;

/**
 * Education addon-owned lifecycle admission for catalogue operations.
 * Purchase admission is stricter and remains in EducationPurchaseAdmissionPolicy.
 */
final class EducationServiceAdmissionPolicy
{
    private const ADDON_KEY = 'service.education';
    private const FEATURE_KEY = 'service.education';

    /** @return array{available:bool,operations:list<string>,feature:array{is_enabled:bool,is_implemented:bool},addon_lifecycle:array{status:string,compatibility_status:string,implementation_registered:bool}} */
    public function snapshot(string $operation): array
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', self::FEATURE_KEY)->first();
        $addon = PlatformAddon::query()->where('addon_key', self::ADDON_KEY)->first();
        $implementation = app(AddonImplementationRegistry::class)->find(self::ADDON_KEY);
        $operations = $implementation['operations'] ?? [];
        $registered = in_array($operation, $operations, true);
        $assessment = $addon === null
            ? ['available' => false]
            : app(AddonLifecyclePolicy::class)->assessAdmission(
                (string) $addon->status,
                (string) $addon->compatibility_status,
                $registered,
                true,
                $feature !== null && (bool) $feature->is_enabled && (bool) $feature->is_implemented,
            );

        return [
            'available' => (bool) ($assessment['available'] ?? false),
            'operations' => $operations,
            'feature' => [
                'is_enabled' => (bool) ($feature?->is_enabled ?? false),
                'is_implemented' => (bool) ($feature?->is_implemented ?? false),
            ],
            'addon_lifecycle' => [
                'status' => (string) ($addon?->status ?? 'not_installed'),
                'compatibility_status' => (string) ($addon?->compatibility_status ?? 'unknown'),
                'implementation_registered' => $implementation !== null,
            ],
        ];
    }
}
