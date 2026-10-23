<?php

namespace App\Services\Addons;

use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Service-owned admission policy for VTU website-service requests.
 *
 * Core continues to own authentication, request persistence, audit events and
 * admin review. This policy owns the feature/addon/operation decision only.
 */
final class VtuWebsiteRequestAdmissionPolicy
{
    private const ADDON_KEY = 'service.vtu-website-requests';
    private const FEATURE_KEY = 'service.vtu_website_requests';

    /** @return array{available:bool, feature_enabled:bool, addon_status:?string, compatibility_status:?string, operation_registered:bool} */
    public function snapshot(string $operation): array
    {
        $feature = PlatformFeatureControl::query()
            ->where('feature_key', self::FEATURE_KEY)
            ->first();
        $addon = PlatformAddon::query()
            ->where('addon_key', self::ADDON_KEY)
            ->first();
        $implementation = app(AddonImplementationRegistry::class)->find(self::ADDON_KEY);
        $registered = $implementation !== null
            && in_array($operation, $implementation['operations'], true);

        $available = $addon !== null && app(AddonLifecyclePolicy::class)->assessAdmission(
            (string) $addon->status,
            (string) $addon->compatibility_status,
            $registered,
            true,
            $feature !== null && $feature->is_enabled && $feature->is_implemented,
        )['available'];

        return [
            'available' => $available,
            'feature_enabled' => $feature !== null && $feature->is_enabled && $feature->is_implemented,
            'addon_status' => $addon?->status,
            'compatibility_status' => $addon?->compatibility_status,
            'operation_registered' => $registered,
        ];
    }

    public function isAvailable(string $operation): bool
    {
        return $this->snapshot($operation)['available'];
    }

    public function assertCanSubmit(): void
    {
        abort_unless(
            $this->isAvailable('vtu-website-requests.store'),
            503,
            'VTU website requests are not accepting submissions right now.',
        );
    }
}
