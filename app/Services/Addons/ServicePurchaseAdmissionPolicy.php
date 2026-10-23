<?php

namespace App\Services\Addons;

use App\Models\VtuProduct;
use InvalidArgumentException;

/**
 * Enforces that a service has both its own purchase entry and the shared Core
 * order operation registered. A shared handler alone must never admit a service.
 */
final class ServicePurchaseAdmissionPolicy
{
    /**
     * Resolve addon ownership and the required service-specific operation.
     * Legacy addon keys remain supported, but no longer substitute a generic
     * Core operation for the service's own admission operation.
     *
     * @return array{addon_key:string, operation:string}
     */
    public function mappingForProduct(VtuProduct $product): array
    {
        $metadata = is_array($product->metadata) ? $product->metadata : [];
        $addonKey = trim((string) ($metadata['addon_key'] ?? ''));

        if ($addonKey === '') {
            $addonKey = match ($product->service_type) {
                'airtime' => 'service.airtime',
                'data' => 'service.data',
                'electricity-bill' => 'service.electricity',
                'tv-subscription' => 'service.tv',
                default => '',
            };
        }

        if ($addonKey === '') {
            throw new InvalidArgumentException('This service has no registered add-on mapping for new purchases.');
        }

        $operation = match (true) {
            in_array($addonKey, ['service.airtime', 'service.vtu', 'service.airtime_data'], true)
                && $product->service_type === 'airtime' => 'service.airtime.purchase',
            in_array($addonKey, ['service.data', 'service.vtu', 'service.airtime_data'], true)
                && $product->service_type === 'data' => 'service.data.purchase',
            in_array($addonKey, ['service.electricity', 'service.bills', 'service.vtu'], true)
                && $product->service_type === 'electricity-bill' => 'service.electricity.purchase',
            in_array($addonKey, ['service.tv', 'service.bills', 'service.vtu'], true)
                && $product->service_type === 'tv-subscription' => 'service.tv.purchase',
            default => null,
        };

        if ($operation === null) {
            throw new InvalidArgumentException('This service has no registered add-on mapping for new purchases.');
        }

        return ['addon_key' => $addonKey, 'operation' => $operation];
    }

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
