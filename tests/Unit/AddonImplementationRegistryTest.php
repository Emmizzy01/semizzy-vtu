<?php

namespace Tests\Unit;

use App\Http\Controllers\EducationServiceController;
use App\Http\Controllers\StreamingSubscriptionController;
use App\Http\Controllers\Vtu\VtuOrderController;
use App\Http\Controllers\VtuWebsiteRequestController;
use App\Services\Addons\AddonImplementationRegistry;
use PHPUnit\Framework\TestCase;

class AddonImplementationRegistryTest extends TestCase
{
    public function test_only_explicitly_reviewed_in_application_implementations_are_registered(): void
    {
        $registry = new AddonImplementationRegistry();

        $this->assertTrue($registry->isRegistered('service.vtu'));
        $this->assertTrue($registry->isRegistered('service.airtime_data'));
        $this->assertTrue($registry->isRegistered('service.electricity'));
        $this->assertTrue($registry->isRegistered('service.tv'));
        $this->assertTrue($registry->isRegistered('service.bills'));
        $this->assertTrue($registry->isRegistered('service.education'));
        $this->assertTrue($registry->isRegistered('service.streaming'));
        $this->assertTrue($registry->isRegistered('service.vtu-website-requests'));
        $this->assertFalse($registry->isRegistered('service.unreviewed'));
        $this->assertFalse($registry->isRegistered('core.wallet'));
    }

    public function test_registered_entries_point_to_existing_application_handlers_and_named_operations(): void
    {
        $registry = new AddonImplementationRegistry();

        $this->assertSame(VtuOrderController::class, $registry->find('service.vtu')['handler']);
        $this->assertContains('wallet.vtu.orders.create', $registry->find('service.vtu')['operations']);
        $this->assertSame(EducationServiceController::class, $registry->find('service.education')['handler']);
        $this->assertSame(StreamingSubscriptionController::class, $registry->find('service.streaming')['handler']);
        $this->assertSame(VtuWebsiteRequestController::class, $registry->find('service.vtu-website-requests')['handler']);
        $this->assertArrayHasKey('service.vtu', $registry->all());
        $this->assertContains('wallet.vtu.orders.create', $registry->find('service.airtime_data')['operations']);
        $this->assertContains('wallet.vtu.billers.verify', $registry->find('service.bills')['operations']);
    }
}
