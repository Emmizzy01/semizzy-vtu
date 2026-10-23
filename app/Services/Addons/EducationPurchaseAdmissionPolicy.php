<?php

namespace App\Services\Addons;

use LogicException;

/**
 * Education addon-owned admission boundary for new purchases only.
 * Accepted-order reconciliation and delivery deliberately do not call this policy.
 */
final class EducationPurchaseAdmissionPolicy
{
    public function assertAvailable(): void
    {
        $assessment = app(AddonOperationAdmissionPolicy::class)->assess(
            'service.education',
            'service.education',
            'education-services.purchase',
            false,
        );

        if (! $assessment['available']) {
            throw new LogicException(
                'Education purchases are disabled at the addon execution boundary: '.$assessment['reason'].'.'
            );
        }
    }
}
