<?php

namespace App\Services\Education;

use App\Models\EducationServiceProduct;
use App\Models\PlatformFeatureControl;
use App\Models\User;
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

        $feature = PlatformFeatureControl::query()->where('feature_key', 'service.education')->first();
        if ($feature === null || ! $feature->is_enabled || ! $feature->is_implemented) {
            throw new LogicException('Education purchases are disabled until the service is verified and implemented.');
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
