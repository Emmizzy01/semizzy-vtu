<?php

namespace App\Services\Addons;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use LogicException;

/**
 * Education addon-owned admission boundary for new purchases only.
 * Accepted-order reconciliation and delivery deliberately do not call this policy.
 */
final class EducationPurchaseAdmissionPolicy
{
    public function assertAvailable(): void
    {
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->first();
        $addon = PlatformAddon::query()->where('addon_key', 'service.education')->first();
        $implementation = app(AddonImplementationRegistry::class)->find('service.education');
        $purchaseRegistered = $implementation !== null
            && in_array('education-services.purchase', $implementation['operations'], true);

        $assessment = $addon === null || $feature === null
            ? ['available' => false, 'reason' => 'addon_or_feature_control_missing']
            : app(AddonLifecyclePolicy::class)->assessAdmission(
                (string) $addon->status,
                (string) $addon->compatibility_status,
                $purchaseRegistered,
                true,
                (bool) $feature->is_enabled && (bool) $feature->is_implemented,
            );

        if (! ($assessment['available'] ?? false)) {
            throw new LogicException(
                'Education purchases are disabled at the addon execution boundary: '.($assessment['reason'] ?? 'admission_unavailable').'.'
            );
        }
    }
}
