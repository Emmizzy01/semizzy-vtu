<?php

namespace App\Services\Addons;

use LogicException;

/**
 * Education addon-owned admission boundary for new purchases only.
 * Accepted-order reconciliation and delivery deliberately do not call this policy.
 */
final class EducationPurchaseAdmissionPolicy
{
    /** @return array{available:bool,reason:string,operations:list<string>,feature:array,addon_lifecycle:array} */
    public function snapshot(): array
    {
        return app(AddonOperationAdmissionPolicy::class)->assess(
            'service.education',
            'service.education',
            'education-services.purchase',
            false,
        );
    }

    public function assertAvailable(): void
    {
        $assessment = $this->snapshot();

        if (! $assessment['available']) {
            throw new LogicException(
                'Education purchases are disabled at the addon execution boundary: '.$assessment['reason'].'.'
            );
        }
    }
}
