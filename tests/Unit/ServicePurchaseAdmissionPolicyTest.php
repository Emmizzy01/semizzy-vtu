<?php

namespace Tests\Unit;

use App\Models\VtuProduct;
use App\Services\Addons\ServicePurchaseAdmissionPolicy;
use InvalidArgumentException;
use Tests\TestCase;

class ServicePurchaseAdmissionPolicyTest extends TestCase
{
    public function test_independent_service_addons_resolve_their_own_purchase_operations(): void
    {
        $policy = app(ServicePurchaseAdmissionPolicy::class);

        foreach ([
            ['airtime', 'service.airtime', 'service.airtime.purchase'],
            ['data', 'service.data', 'service.data.purchase'],
            ['electricity-bill', 'service.electricity', 'service.electricity.purchase'],
            ['tv-subscription', 'service.tv', 'service.tv.purchase'],
        ] as [$serviceType, $addonKey, $operation]) {
            $product = new VtuProduct(['service_type' => $serviceType, 'metadata' => []]);
            $this->assertSame(
                ['addon_key' => $addonKey, 'operation' => $operation],
                $policy->mappingForProduct($product),
            );
        }
    }

    public function test_legacy_aggregate_mappings_remain_explicit_and_service_scoped(): void
    {
        $policy = app(ServicePurchaseAdmissionPolicy::class);
        $product = new VtuProduct(['service_type' => 'airtime', 'metadata' => ['addon_key' => 'service.airtime_data']]);

        $this->assertSame(
            ['addon_key' => 'service.airtime_data', 'operation' => 'wallet.vtu.orders.create'],
            $policy->mappingForProduct($product),
        );
    }

    public function test_unknown_service_does_not_receive_an_addon_mapping(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ServicePurchaseAdmissionPolicy::class)->mappingForProduct(
            new VtuProduct(['service_type' => 'unknown-service', 'metadata' => []]),
        );
    }
}
