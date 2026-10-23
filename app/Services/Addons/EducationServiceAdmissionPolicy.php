<?php

namespace App\Services\Addons;

/**
 * Education addon-owned lifecycle admission for catalogue operations.
 * Purchase admission remains stricter in EducationPurchaseAdmissionPolicy.
 */
final class EducationServiceAdmissionPolicy
{
    public function snapshot(string $operation): array
    {
        return app(AddonOperationAdmissionPolicy::class)->assess(
            'service.education',
            'service.education',
            $operation,
            true,
        );
    }
}
