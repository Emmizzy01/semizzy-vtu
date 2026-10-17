<?php

namespace App\Services\Vtu;

/**
 * Fail closed on post-dispatch failover until a provider's definitive-failure
 * semantics have been verified and explicitly enabled by operations.
 */
class ProviderFailoverPolicy
{
    /** @param array<string, mixed> $result */
    public function mayFailover(string $provider, array $result): bool
    {
        if (($result['status'] ?? null) !== 'failed'
            || ($result['definitive_failure'] ?? false) !== true) {
            return false;
        }

        $verifiedProviders = config('services.vtu.multi_provider.definitive_failure_providers', []);

        return is_array($verifiedProviders)
            && in_array($provider, $verifiedProviders, true);
    }
}
