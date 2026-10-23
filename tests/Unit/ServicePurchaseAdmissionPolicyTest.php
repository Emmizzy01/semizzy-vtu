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

    public function test_legacy_addon_keys_still_require_the_matching_service_operation(): void
    {
        $policy = app(ServicePurchaseAdmissionPolicy::class);

        foreach ([
            ['airtime', 'service.airtime_data', 'service.airtime.purchase'],
            ['data', 'service.vtu', 'service.data.purchase'],
            ['electricity-bill', 'service.bills', 'service.electricity.purchase'],
            ['tv-subscription', 'service.vtu', 'service.tv.purchase'],
        ] as [$serviceType, $addonKey, $operation]) {
            $this->assertSame(
                ['addon_key' => $addonKey, 'operation' => $operation],
                $policy->mappingForProduct(new VtuProduct([
                    'service_type' => $serviceType,
                    'metadata' => ['addon_key' => $addonKey],
                ])),
            );
        }
    }

    public function test_unknown_service_does_not_receive_an_addon_mapping(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ServicePurchaseAdmissionPolicy::class)->mappingForProduct(
            new VtuProduct(['service_type' => 'unknown-service', 'metadata' => []]),
        );
    }
}
