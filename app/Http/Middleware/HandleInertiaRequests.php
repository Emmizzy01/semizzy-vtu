<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Services\IntegrationSettings;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $settings = app(IntegrationSettings::class);
        return [
            ...parent::share($request),
            'appName' => (string) $settings->get('site.name', config('app.name')),
            'platform' => ['name' => (string) $settings->get('site.name', config('app.name', 'Semizzy')), 'skin' => (string) $settings->get('site.theme_skin', 'emerald')],
            'auth' => [
                'user' => $request->user(),
            ],
        ];
    }
}
