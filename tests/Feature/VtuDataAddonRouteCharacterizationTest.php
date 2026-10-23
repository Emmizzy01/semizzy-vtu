<?php

namespace Tests\Feature;

use App\Http\Controllers\Vtu\VtuOrderController;
use App\Services\Addons\AddonImplementationRegistry;
use App\Services\Addons\AirtimePurchaseRuntime;
use App\Services\Addons\DataPurchaseRuntime;
use App\Services\Addons\ElectricityPurchaseRuntime;
use App\Services\Addons\TvPurchaseRuntime;
use App\Services\Addons\ServicePurchaseRuntimeRegistry;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Characterization tests for the existing airtime/data route contract.
 *
 * These assertions intentionally capture the compatibility boundary before
 * further service ownership moves into addons. They do not imply that airtime
 * and data already have independent runtime handlers.
 */
class VtuDataAddonRouteCharacterizationTest extends TestCase
{
    public function test_airtime_and_data_purchase_routes_keep_their_existing_names_and_handlers(): void
    {
        $routes = app('router')->getRoutes();

        $expected = [
            'wallet.vtu.airtime' => ['GET', 'wallet/vtu/airtime'],
            'wallet.vtu.data' => ['GET', 'wallet/vtu/data'],
            'wallet.vtu.products' => ['GET', 'wallet/vtu/products'],
            'wallet.vtu.orders.create' => ['POST', 'wallet/vtu/orders'],
            'wallet.vtu.orders.bulk' => ['POST', 'wallet/vtu/orders/bulk'],
            'wallet.vtu.orders.recover' => ['GET', 'wallet/vtu/orders/recover'],
            'wallet.vtu.orders.bulk.recover' => ['GET', 'wallet/vtu/orders/bulk-recover'],
        ];

        foreach ($expected as $name => [$method, $uri]) {
            $route = $routes->getByName($name);

            $this->assertInstanceOf(Route::class, $route, "Missing route [{$name}].");
            $this->assertSame($uri, $route->uri(), "Route URI changed for [{$name}].");
            $this->assertContains($method, $route->methods(), "Route method changed for [{$name}].");
            $this->assertContains('auth', $route->gatherMiddleware(), "Authentication middleware missing for [{$name}].");
        }

        foreach (['wallet.vtu.products', 'wallet.vtu.orders.create', 'wallet.vtu.orders.bulk', 'wallet.vtu.orders.recover', 'wallet.vtu.orders.bulk.recover'] as $name) {
            $this->assertSame(
                VtuOrderController::class,
                $routes->getByName($name)?->getControllerClass(),
                "Handler changed for [{$name}].",
            );
        }

        $this->assertContains('throttle:10,1', $routes->getByName('wallet.vtu.orders.create')->gatherMiddleware());
        $this->assertContains('throttle:5,1', $routes->getByName('wallet.vtu.orders.bulk')->gatherMiddleware());
        $this->assertContains('throttle:30,1', $routes->getByName('wallet.vtu.orders.recover')->gatherMiddleware());
        $this->assertContains('throttle:30,1', $routes->getByName('wallet.vtu.orders.bulk.recover')->gatherMiddleware());
    }

    public function test_airtime_and_data_registry_entries_preserve_the_current_compatibility_handler(): void
    {
        $registry = app(AddonImplementationRegistry::class);
        $airtime = $registry->find('service.airtime');
        $data = $registry->find('service.data');

        $this->assertNotNull($airtime);
        $this->assertNotNull($data);
        $this->assertSame(VtuOrderController::class, $airtime['handler']);
        $this->assertSame(VtuOrderController::class, $data['handler']);
        $this->assertContains('service.airtime.purchase', $airtime['operations']);
        $this->assertContains('service.data.purchase', $data['operations']);
        $this->assertNotContains('service.data.purchase', $airtime['operations']);
        $this->assertNotContains('service.airtime.purchase', $data['operations']);
        $this->assertContains('wallet.vtu.orders.create', $airtime['operations']);
        $this->assertContains('wallet.vtu.orders.bulk', $data['operations']);
    }
    public function test_shared_route_contract_dispatches_service_specific_airtime_and_data_runtime_policies(): void
    {
        $runtimeRegistry = app(ServicePurchaseRuntimeRegistry::class);

        $this->assertInstanceOf(AirtimePurchaseRuntime::class, $runtimeRegistry->forService('airtime'));
        $this->assertInstanceOf(DataPurchaseRuntime::class, $runtimeRegistry->forService('data'));
        $this->assertInstanceOf(ElectricityPurchaseRuntime::class, $runtimeRegistry->forService('electricity-bill'));
        $this->assertInstanceOf(TvPurchaseRuntime::class, $runtimeRegistry->forService('tv-subscription'));

        // Public purchase and recovery routes intentionally retain their existing
        // shared endpoint contracts while only service-specific policy is extracted.
        $routes = app('router')->getRoutes();
        $this->assertSame(VtuOrderController::class, $routes->getByName('wallet.vtu.orders.create')?->getControllerClass());
        $this->assertSame(VtuOrderController::class, $routes->getByName('wallet.vtu.orders.recover')?->getControllerClass());
    }

}
