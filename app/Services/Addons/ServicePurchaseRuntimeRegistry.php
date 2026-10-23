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
        return match ($serviceType) {
            'airtime' => app(AirtimePurchaseRuntime::class),
            'data' => app(DataPurchaseRuntime::class),
            'electricity-bill' => app(ElectricityPurchaseRuntime::class),
            'tv-subscription' => app(TvPurchaseRuntime::class),
            default => null,
        };
    }
}
