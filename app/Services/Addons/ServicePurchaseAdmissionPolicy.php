<?php

namespace App\Services\Addons;

/**
 * Enforces that a service has both its own purchase entry and the shared Core
 * order operation registered. A shared handler alone must never admit a service.
 */
final class ServicePurchaseAdmissionPolicy
{
    /**
     * @param array{handler: class-string, operations: list<string>}|null $implementation
     */
    public function isRegistered(?array $implementation, string $serviceOperation): bool
    {
        if ($implementation === null) {
            return false;
        }

        $operations = $implementation['operations'] ?? [];

        return in_array($serviceOperation, $operations, true)
            && in_array('wallet.vtu.orders.create', $operations, true);
    }
}
