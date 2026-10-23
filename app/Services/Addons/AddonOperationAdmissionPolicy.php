<?php

namespace App\Services\Addons;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;

/**
 * Shared lifecycle assessment for a specific registered addon operation.
 * Service wrappers may add domain-specific gate explanations, but cannot
 * bypass this feature, lifecycle, compatibility or registration decision.
 */
final class AddonOperationAdmissionPolicy
{
    /**
     * @return array{
     *   available:bool,
     *   reason:string,
     *   operations:list<string>,
     *   feature:array{is_enabled:bool,is_implemented:bool},
     *   addon_lifecycle:array{status:string,compatibility_status:string,implementation_registered:bool,operation_registered:bool}
     * }
     */
    public function assess(
        string $addonKey,
        string $featureKey,
        string $operation,
        bool $providerReady,
    ): array {
        $feature = PlatformFeatureControl::query()->where('feature_key', $featureKey)->first();
        $addon = PlatformAddon::query()->where('addon_key', $addonKey)->first();
        $implementation = app(AddonImplementationRegistry::class)->find($addonKey);
        $operations = $implementation['operations'] ?? [];
        $registered = str_starts_with($operation, 'service.')
            && str_ends_with($operation, '.purchase')
                ? app(ServicePurchaseAdmissionPolicy::class)->isRegistered($implementation, $operation)
                : in_array($operation, $operations, true);
        $featureEnabled = $feature !== null
            && (bool) $feature->is_enabled
            && (bool) $feature->is_implemented;

        $assessment = $addon === null
            ? ['available' => false, 'reason' => 'addon_missing']
            : app(AddonLifecyclePolicy::class)->assessAdmission(
                (string) $addon->status,
                (string) $addon->compatibility_status,
                $registered,
                $providerReady,
                $featureEnabled,
            );

        return [
            'available' => (bool) ($assessment['available'] ?? false),
            'reason' => (string) ($assessment['reason'] ?? 'admission_unavailable'),
            'operations' => $operations,
            'feature' => [
                'is_enabled' => (bool) ($feature?->is_enabled ?? false),
                'is_implemented' => (bool) ($feature?->is_implemented ?? false),
            ],
            'addon_lifecycle' => [
                'status' => (string) ($addon?->status ?? 'not_installed'),
                'compatibility_status' => (string) ($addon?->compatibility_status ?? 'unknown'),
                'implementation_registered' => $implementation !== null,
                'operation_registered' => $registered,
            ],
        ];
    }
}
