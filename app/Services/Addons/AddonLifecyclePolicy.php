<?php

namespace App\Services\Addons;

/**
 * Pure policy for service admission versus accepted-operation recovery.
 *
 * This does not load code or mutate addon state. Callers supply reviewed code
 * registration, provider readiness, and the existing Core feature-gate result.
 */
class AddonLifecyclePolicy
{
    /** @return array{available:bool, reason:string} */
    public function assessAdmission(
        string $addonStatus,
        string $compatibilityStatus,
        bool $implementationRegistered,
        bool $providerReady,
        bool $featureEnabled,
    ): array {
        if ($addonStatus !== 'enabled') {
            return ['available' => false, 'reason' => 'addon_disabled'];
        }

        if ($compatibilityStatus !== 'compatible') {
            return ['available' => false, 'reason' => 'addon_incompatible'];
        }

        if (! $implementationRegistered) {
            return ['available' => false, 'reason' => 'implementation_unregistered'];
        }

        if (! $featureEnabled) {
            return ['available' => false, 'reason' => 'feature_disabled'];
        }

        if (! $providerReady) {
            return ['available' => false, 'reason' => 'provider_unavailable'];
        }

        return ['available' => true, 'reason' => 'ready'];
    }

    /**
     * Core recovery is independent of addon admission. This never authorizes
     * a new provider submission.
     */
    public function mayContinueAcceptedOperation(
        bool $operationAccepted,
        bool $operationTerminal,
        bool $coreRecoveryAvailable,
    ): bool {
        return $operationAccepted && ! $operationTerminal && $coreRecoveryAvailable;
    }
}
