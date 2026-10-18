<?php

namespace App\Services\Education;

use App\Models\EducationServiceProduct;
use LogicException;

/**
 * Resolves only an explicitly implemented adapter. A provider name in the
 * catalogue is not evidence that an adapter or merchant authorization exists.
 */
class EducationProviderRegistry
{
    public function forProduct(EducationServiceProduct $product): EducationProvider
    {
        $key = strtolower(trim((string) $product->provider));

        if ($key === '') {
            throw new LogicException('No education provider is assigned to this product.');
        }

        $binding = 'education.providers.'.$key;

        if (! app()->bound($binding)) {
            throw new LogicException("The education provider adapter [{$key}] is not verified or registered.");
        }

        $provider = app($binding);

        if (! $provider instanceof EducationProvider) {
            throw new LogicException("The education provider adapter [{$key}] does not implement the required transaction contract.");
        }

        return $provider;
    }
}
