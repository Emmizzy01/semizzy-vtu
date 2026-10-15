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
            'platform' => ['name' => (string) $settings->get('site.name', config('app.name', 'Semizzy')), 'skin' => match ((string) $settings->get('site.theme_skin', 'modern-green')) { 'emerald' => 'modern-green', 'teal' => 'navy-teal', 'blue' => 'minimal-blue', 'violet' => 'obsidian-luxe', 'amber' => 'arctic-light', default => (string) $settings->get('site.theme_skin', 'modern-green') }],
            'auth' => [
                'user' => $request->user(),
            ],
        ];
    }
}
