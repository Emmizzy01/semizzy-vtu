<?php

namespace App\Services\Addons;

final class ServicePurchaseRuntimeRegistry
{
    /**
     * Resolve service-specific request policy without moving order execution or
     * financial protections out of Core's VtuOrderService.
     */
    public function forService(?string $serviceType): ?ServicePurchaseRuntimeContract
    {
        $binding = match ($serviceType) {
            'airtime' => AirtimePurchaseRuntime::class,
            'data' => DataPurchaseRuntime::class,
            'electricity-bill' => ElectricityPurchaseRuntime::class,
            'tv-subscription' => TvPurchaseRuntime::class,
            default => null,
        };

        if ($binding === null || ! app()->bound($binding)) {
            return null;
        }

        $runtime = app($binding);

        return $runtime instanceof ServicePurchaseRuntimeContract ? $runtime : null;
    }
}
