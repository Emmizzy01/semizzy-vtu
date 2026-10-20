<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentPasskeyConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = (int) $request->session()->get('passkeys.password_confirmed_at', 0);
        abort_if($confirmedAt < 1 || $confirmedAt < now()->subMinutes(5)->timestamp, 403, 'Confirm your current password before managing passkeys.');

        return $next($request);
    }
}
