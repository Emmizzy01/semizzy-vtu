<?php

namespace App\\Services\\Vtu;

use App\\Models\\VtuProduct;

class BillerVerificationProviderRegistry
{
    /**
     * @param array<string, BillerVerificationProvider> $adapters
     */
    public function __construct(private readonly array $adapters = [])
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

        // Adapter ownership is supplied by the service addon composition root.
        // Never guess a provider or fall back to a different provider.
        $adapter = $this->adapters[$key] ?? null;

        return $adapter instanceof BillerVerificationProvider ? $adapter : null;
    }
}
