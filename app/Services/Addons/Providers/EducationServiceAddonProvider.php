<?php

namespace App\Services\Addons\Providers;

use App\Services\Education\Providers\DatawayEducationProvider;
use App\Services\Education\Providers\GsubzEducationProvider;
use App\Services\Education\Providers\SogoEducationProvider;
use App\Services\Education\Providers\VtpassEducationProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Education addon adapter composition root. Registering an adapter does not
 * authorize customer purchases; addon admission remains a separate Core gate.
 */
class EducationServiceAddonProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind('education.providers.sogo', SogoEducationProvider::class);
        $this->app->bind('education.providers.gsubz', GsubzEducationProvider::class);
        $this->app->bind('education.providers.vtpass', VtpassEducationProvider::class);
        $this->app->bind('education.providers.dataway', DatawayEducationProvider::class);
    }
}
