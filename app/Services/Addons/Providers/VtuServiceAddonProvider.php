<?php

namespace App\Services\Addons\Providers;

use App\Services\IntegrationSettings;
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
