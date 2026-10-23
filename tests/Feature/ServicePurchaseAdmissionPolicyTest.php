<?php

namespace Tests\Feature;

use App\Models\VtuProduct;
use App\Services\Addons\ServicePurchaseAdmissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ServicePurchaseAdmissionPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * These categories currently have no reviewed purchase runtime/provider
     * contract. A shared VTU controller or catalogue row must not silently
     * turn them into executable purchases.
     */
    public function test_unconverted_service_categories_fail_closed_for_new_purchase_admission(): void
    {
        foreach ([
            'government-verification',
            'number-verification',
            'smm',
            'social-media',
            'betting',
        ] as $serviceType) {
            $product = new VtuProduct([
                'service_type' => $serviceType,
                'network' => 'unverified',
                'code' => 'unverified-'.$serviceType,
                'name' => 'Unverified service',
                'price_minor' => 100,
                'currency' => 'NGN',
                'is_active' => true,
                'metadata' => [],
            ]);

            try {
                app(ServicePurchaseAdmissionPolicy::class)->mappingForProduct($product);
                $this->fail("Service type [{$serviceType}] unexpectedly received a purchase addon mapping.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'This service has no registered add-on mapping for new purchases.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_unknown_service_cannot_be_admitted_by_a_generic_core_handler(): void
    {
        $product = new VtuProduct([
            'service_type' => 'government-verification',
            'network' => 'unknown',
            'code' => 'government-verification-unmapped',
            'name' => 'Government verification',
            'price_minor' => 100,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['addon_key' => 'service.vtu'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This service has no registered add-on mapping for new purchases.');

        app(ServicePurchaseAdmissionPolicy::class)->mappingForProduct($product);
    }
}
