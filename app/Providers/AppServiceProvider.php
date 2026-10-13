<?php

namespace App\Providers;

use App\Models\User;\nuse App\Models\UserPermission;\nuse Illuminate\Support\Facades\Gate;\nuse Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
