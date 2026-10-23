<?php

namespace App\Services\Addons\Providers;

use App\Services\IntegrationSettings;
use App\Services\Addons\AirtimePurchaseRuntime;
use App\Services\Addons\DataPurchaseRuntime;
use App\Services\Addons\ElectricityPurchaseRuntime;
use App\Services\Addons\TvPurchaseRuntime;
use App\Services\Vtu\DatawayProviderClient;
use App\Services\Vtu\GsubzProviderClient;
use App\Services\Vtu\MultiProviderVtuRouter;
use App\Services\Vtu\SogoProviderClient;
use App\Services\Vtu\VtpassProviderClient;
use App\Services\Vtu\VtuProvider;
use App\Services\Vtu\VtuProviderClient;
use Illuminate\Support\ServiceProvider;

/**
 * VTU addon composition root. This wires adapters only; Core admission,
 * wallet accounting, idempotency, and accepted-order recovery remain separate.
 */
class VtuServiceAddonProvider extends ServiceProvider
{
    public function register(): void
    {
        // Service-specific request policies are composed at the addon boundary.
        // Core keeps order admission, locking, idempotency and financial execution.
        $this->app->bind(AirtimePurchaseRuntime::class);
        $this->app->bind(DataPurchaseRuntime::class);
        $this->app->bind(ElectricityPurchaseRuntime::class);
        $this->app->bind(TvPurchaseRuntime::class);

        $this->app->bind(VtuProvider::class, function ($app): VtuProvider {
            return new MultiProviderVtuRouter(
                $app->make(VtuProviderClient::class),
                $app->make(VtpassProviderClient::class),
                $app->make(IntegrationSettings::class),
                $app->make(SogoProviderClient::class),
                $app->make(GsubzProviderClient::class),
                $app->make(DatawayProviderClient::class),
            );
        });
    }
}
