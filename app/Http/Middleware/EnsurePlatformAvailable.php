<?php

namespace App\Http\Middleware;

use App\Models\UserPermission;
use App\Services\IntegrationSettings;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        // Provider webhooks must continue through maintenance mode so financial events are not lost.
        if ($request->is('api/payments/webhooks/*')) return $next($request);
        $settings = app(IntegrationSettings::class);
        if (! filter_var($settings->get('site.maintenance_mode', false), FILTER_VALIDATE_BOOLEAN)) return $next($request);
        $user = $request->user();
        $isAdmin = $user && UserPermission::query()->where('user_id', $user->id)->where('permission', 'staff.permissions.manage')->exists();
        if ($isAdmin) return $next($request);
        if ($request->expectsJson()) return response()->json(['message' => 'Semizzy is temporarily unavailable for maintenance. Please try again shortly.'], 503);
        return Inertia::render('Maintenance', [
            'siteName' => (string) $settings->get('site.name', config('app.name', 'Semizzy')),
            'supportEmail' => (string) $settings->get('site.support_email', ''),
            'supportPhone' => (string) $settings->get('site.support_phone', ''),
        ])->toResponse($request)->setStatusCode(503);
    }
}
