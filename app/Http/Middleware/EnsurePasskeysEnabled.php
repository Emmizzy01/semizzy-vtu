<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasskeysEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('passkeys.enabled', false), 404);

        $rpId = (string) config('passkeys.relying_party_id', '');
        $origins = (array) config('passkeys.allowed_origins', []);
        abort_if($rpId === '' || $origins === [] || ! config('app.key'), 503, 'Passkey authentication is not configured.');

        foreach ($origins as $origin) {
            if (! is_string($origin) || ! str_starts_with(strtolower($origin), 'https://')) {
                abort(503, 'Passkey authentication requires HTTPS origins.');
            }
        }

        return $next($request);
    }
}
