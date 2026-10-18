<?php

namespace App\Services\Education\Providers;

use App\Models\EducationServiceProduct;
use App\Services\Education\EducationProvider;
use LogicException;

/**
 * Deliberately fail-closed adapter boundary. Dataway must not be enabled until
 * its education-specific validation, submission, lookup and final-failure
 * contract is confirmed for Semizzy's merchant account.
 */
final class DatawayEducationProvider implements EducationProvider
{
    private function unavailable(): never
    {
        throw new LogicException('Dataway education is not implemented: provider-specific education endpoints and definitive transaction-recovery semantics remain unverified.');
    }

    public function assertConfigured(): void { $this->unavailable(); }

    public function validateCandidate(EducationServiceProduct $product, array $candidate): array
    {
        $this->unavailable();
    }

    public function submit(string $orderReference, EducationServiceProduct $product, array $candidate): array
    {
        $this->unavailable();
    }

    public function lookup(string $orderReference, EducationServiceProduct $product): array
    {
        $this->unavailable();
    }
}
