<?php

namespace App\Services\Addons;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;

/**
 * Streaming addon admission snapshot. Catalogue management is not purchase
 * authorization; customer purchase stays unavailable until a reviewed purchase
 * operation and provider fulfilment/recovery contract are registered.
 */
final class StreamingPurchaseAdmissionPolicy
{
    /** @return array{
     *   available:bool,
     *   feature:array{is_enabled:bool,is_implemented:bool},
     *   addon_lifecycle:array{status:string,compatibility_status:string,implementation_registered:bool,catalogue_operation_registered:bool,purchase_operation_registered:bool},
     *   gate_reason:string
     * }
     */
    public function snapshot(): array
    {
        $feature = PlatformFeatureControl::query()
            ->where('feature_key', 'service.streaming_subscriptions')
            ->first();
        $addon = PlatformAddon::query()->where('addon_key', 'service.streaming')->first();
        $implementation = app(AddonImplementationRegistry::class)->find('service.streaming');
        $operations = $implementation['operations'] ?? [];
        $purchaseRegistered = in_array('streaming-subscriptions.purchase', $operations, true);
        $assessment = $addon === null
            ? ['available' => false]
            : app(AddonLifecyclePolicy::class)->assessAdmission(
                (string) $addon->status,
                (string) $addon->compatibility_status,
                $purchaseRegistered,
                false,
                $feature !== null && (bool) $feature->is_enabled && (bool) $feature->is_implemented,
            );

        return [
            'available' => (bool) ($assessment['available'] ?? false),
            'feature' => [
                'is_enabled' => (bool) ($feature?->is_enabled ?? false),
                'is_implemented' => (bool) ($feature?->is_implemented ?? false),
            ],
            'addon_lifecycle' => [
                'status' => (string) ($addon?->status ?? 'not_installed'),
                'compatibility_status' => (string) ($addon?->compatibility_status ?? 'unknown'),
                'implementation_registered' => $implementation !== null,
                'catalogue_operation_registered' => in_array('streaming-subscriptions.index', $operations, true),
                'purchase_operation_registered' => $purchaseRegistered,
            ],
            'gate_reason' => 'Purchases are locked until a provider contract, transaction lookup and accepted-order recovery workflow are verified; no streaming purchase operation is registered.',
        ];
    }
}
