<?php

namespace App\Services\Education;

use App\Models\EducationServiceProduct;
use App\Models\PlatformAddon;
use App\Models\PlatformFeatureControl;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Security\UserLevelPolicyService;
use App\Services\Addons\AddonImplementationRegistry;
use App\Services\Addons\AddonLifecyclePolicy;
use InvalidArgumentException;
use LogicException;

/**
 * Safe preflight boundary for education transactions.
 * This slice intentionally stops before wallet debit/provider submission.
 */
class EducationPurchaseService
{
    public function __construct(private readonly EducationProviderRegistry $providers)
    {
    }

    /** @param array<string, scalar|null> $candidate */
    public function preflight(User $user, string $productCode, string $idempotencyKey, array $candidate): EducationServiceProduct
    {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 128) {
            throw new InvalidArgumentException('Idempotency key must contain 1 to 128 characters.');
        }

        // This is a server-side execution boundary, not just a page/UI gate.
        // Candidate validation may call an external provider, so admission must
        // be checked before resolving or contacting any provider adapter.
        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->first();
        $addon = PlatformAddon::query()->where('addon_key', 'service.education')->first();
        $implementation = app(AddonImplementationRegistry::class)->find('service.education');
        $purchaseOperationRegistered = $implementation !== null
            && in_array('education-services.purchase', $implementation['operations'], true);
        $assessment = $addon === null || $feature === null
            ? ['available' => false, 'reason' => 'addon_or_feature_control_missing']
            : app(AddonLifecyclePolicy::class)->assessAdmission(
                (string) $addon->status,
                (string) $addon->compatibility_status,
                $purchaseOperationRegistered,
                false,
                (bool) $feature->is_enabled && (bool) $feature->is_implemented,
            );

        // Provider readiness remains false until the education purchase
        // contract is explicitly registered and verified. This deliberately
        // blocks the currently unregistered purchase operation.
        if (! $assessment['available']) {
            throw new LogicException(
                'Education purchases are disabled at the addon execution boundary: '.$assessment['reason'].'.'
            );
        }

        $product = EducationServiceProduct::query()->where('code', $productCode)->firstOrFail();
        if (! $product->is_active || ! $product->purchase_approved || $product->currency !== 'NGN') {
            throw new LogicException('This education product is not approved for purchase.');
        }

        $walletId = (int) (Wallet::query()->where('user_id', $user->getKey())->where('currency', 'NGN')->value('id') ?? 0);
        $policyRejection = app(UserLevelPolicyService::class)->rejectionReasonForAmount(
            $user,
            $walletId,
            (int) $product->price_minor,
        );
        if ($policyRejection !== null) {
            throw new LogicException($policyRejection);
        }

        if ($product->requires_candidate_validation && $candidate === []) {
            throw new InvalidArgumentException('Candidate details are required for this education product.');
        }

        $provider = $this->providers->forProduct($product);
        $provider->assertConfigured();
        $validation = $provider->validateCandidate($product, $candidate);

        if (($validation['status'] ?? 'unknown') !== 'valid') {
            throw new LogicException('Candidate validation was not positively confirmed; no wallet debit was made.');
        }

        return $product;
    }
}
