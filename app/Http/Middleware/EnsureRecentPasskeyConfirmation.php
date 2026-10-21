<?php

namespace App\\Http\\Middleware;

use Closure;
use Illuminate\\Http\\Request;
use Symfony\\Component\\HttpFoundation\\Response;

class EnsureRecentPasskeyConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        // Fail closed unless the request has both a session and an authenticated owner.
        abort_unless($request->hasSession() && $request->user(), 403, 'Confirm your current password before managing passkeys.');

        $confirmedAt = (int) $request->session()->get('passkeys.password_confirmed_at', 0);
        $confirmedPasswordHash = $request->session()->get('passkeys.password_confirmed_password_hash');
        $currentPasswordHash = hash('sha256', (string) $request->user()->password);

        // A recent timestamp alone is insufficient: password changes must invalidate
        // prior confirmation even when the five-minute window has not elapsed.
        abort_if(
            $confirmedAt < 1
            || $confirmedAt < now()->subMinutes(5)->timestamp
            || !is_string($confirmedPasswordHash)
            || !hash_equals($currentPasswordHash, $confirmedPasswordHash),
            403,
            'Confirm your current password before managing passkeys.'
        );

        return $next($request);
    }
}
