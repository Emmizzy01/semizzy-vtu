<?php

namespace App\Services\Vtu;

use App\Models\VtuProduct;

class BillerVerificationProviderRegistry
{
    public function __construct(private readonly VtpassProviderClient $vtpass)
    {
    }

    public function forProduct(VtuProduct $product): ?BillerVerificationProvider
    {
        $metadata = is_array($product->metadata) ? $product->metadata : [];
        $key = strtolower(trim((string) ($metadata['catalog_source'] ?? '')));

        if ($key === '') {
            $mappings = is_array($metadata['provider_mappings'] ?? null) ? $metadata['provider_mappings'] : [];
            $key = count($mappings) === 1 ? strtolower((string) array_key_first($mappings)) : '';
        }

        // Add adapters here only when their actual biller-verification API
        // contract is implemented and tested. Never fall back to another provider.
        $adapters = ['vtpass' => $this->vtpass];

        return $adapters[$key] ?? null;
    }
}
