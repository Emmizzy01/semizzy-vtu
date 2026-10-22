<?php

namespace App\Providers;

use App\Services\IntegrationSettings;
use App\Services\Education\Providers\DatawayEducationProvider;
use App\Services\Education\Providers\GsubzEducationProvider;
use App\Services\Education\Providers\SogoEducationProvider;
use App\Services\Education\Providers\VtpassEducationProvider;
use App\Services\Vtu\DatawayProviderClient;
use App\Services\Vtu\GsubzProviderClient;
use App\Services\Vtu\MultiProviderVtuRouter;
use App\Services\Vtu\SogoProviderClient;
use App\Services\Vtu\VtpassProviderClient;
use App\Services\Vtu\VtuProvider;
use App\Services\Vtu\VtuProviderClient;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for service addon adapters.
 *
 * Existing binding keys are deliberately preserved for compatibility. This
 * provider only wires adapters; purchase admission and Core-owned financial
 * recovery policies remain enforced by their existing services.
 */
class ServiceAddonsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('education.providers.sogo', SogoEducationProvider::class);
        $this->app->bind('education.providers.gsubz', GsubzEducationProvider::class);
        $this->app->bind('education.providers.vtpass', VtpassEducationProvider::class);
        $this->app->bind('education.providers.dataway', DatawayEducationProvider::class);

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
