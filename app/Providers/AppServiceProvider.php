<?php

namespace App\Providers;

use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::define('refunds.approve', function (User $user): bool {
            return UserPermission::query()
                ->where('user_id', $user->id)
                ->where('permission', 'refunds.approve')
                ->exists();
        });
    }
}
