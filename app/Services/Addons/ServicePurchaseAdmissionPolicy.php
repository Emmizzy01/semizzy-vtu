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
     * Legacy mappings remain explicit during the compatibility window.
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
            $addonKey === 'service.airtime' && $product->service_type === 'airtime' => 'service.airtime.purchase',
            $addonKey === 'service.data' && $product->service_type === 'data' => 'service.data.purchase',
            $addonKey === 'service.electricity' && $product->service_type === 'electricity-bill' => 'service.electricity.purchase',
            $addonKey === 'service.tv' && $product->service_type === 'tv-subscription' => 'service.tv.purchase',
            in_array($addonKey, ['service.vtu', 'service.airtime_data'], true)
                && in_array($product->service_type, ['airtime', 'data'], true) => 'wallet.vtu.orders.create',
            in_array($addonKey, ['service.vtu', 'service.bills'], true)
                && in_array($product->service_type, ['electricity-bill', 'tv-subscription'], true) => 'wallet.vtu.orders.create',
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
