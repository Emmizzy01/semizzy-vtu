<?php

namespace Tests\Unit;

use App\Services\Addons\AddonImplementationRegistry;
use PHPUnit\Framework\TestCase;

class AddonPurchaseAdmissionRegistryTest extends TestCase
{
    public function test_streaming_catalogue_is_registered_but_purchase_remains_unavailable_until_provider_recovery_is_verified(): void
    {
        $registry = new AddonImplementationRegistry();
        $implementation = $registry->find('service.streaming');

        $this->assertNotNull($implementation);
        $this->assertContains('streaming-subscriptions.index', $implementation['operations']);
        $this->assertContains('streaming-subscriptions.admin.catalogue', $implementation['operations']);
        $this->assertNotContains('streaming-subscriptions.purchase', $implementation['operations']);
    }

    public function test_education_delivery_is_registered_without_authorizing_new_purchases(): void
    {
        $registry = new AddonImplementationRegistry();
        $implementation = $registry->find('service.education');

        $this->assertNotNull($implementation);
        $this->assertContains('education-services.index', $implementation['operations']);
        $this->assertContains('education-services.delivery', $implementation['operations']);
        $this->assertNotContains('education-services.purchase', $implementation['operations']);
    }
}
