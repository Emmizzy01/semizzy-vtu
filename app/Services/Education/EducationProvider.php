<?php

namespace App\Services\Education;

use App\Models\EducationServiceProduct;

/**
 * Provider contract for education products. Implementations must use the provider's
 * documented candidate-verification, idempotency, lookup and status contracts.
 * No adapter is registered until its live capabilities have been verified.
 */
interface EducationProvider
{
    public function assertConfigured(): void;

    /**
     * Validate candidate details before any wallet debit or provider purchase.
     *
     * @param array<string, scalar|null> $candidate
     * @return array{status: 'valid'|'invalid'|'unknown', reference?: string|null, message?: string|null}
     */
    public function validateCandidate(EducationServiceProduct $product, array $candidate): array;

    /**
     * Submit once with a stable persisted order reference/idempotency identity.
     *
     * @return array{status: 'success'|'failed'|'pending'|'unknown', provider_reference?: string|null, message_code?: string|null}
     */
    public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array;

    /**
     * Resolve an earlier submission using the same provider and persisted reference.
     *
     * @return array{status: 'success'|'failed'|'pending'|'unknown', provider_reference?: string|null, message_code?: string|null}
     */
    public function lookup(string $orderReference, EducationServiceProduct $product): array;
}
