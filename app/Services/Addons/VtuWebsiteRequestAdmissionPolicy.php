<?php

namespace App\Services\Addons;

/**
 * Service-owned admission policy for VTU website-service requests.
 * Core continues to own authentication, request persistence, audit events and
 * admin review. This policy owns the feature/addon/operation decision only.
 */
final class VtuWebsiteRequestAdmissionPolicy
{
    public function snapshot(string $operation): array
    {
        return app(AddonOperationAdmissionPolicy::class)->assess(
            'service.vtu-website-requests',
            'service.vtu_website_requests',
            $operation,
            true,
        );
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
