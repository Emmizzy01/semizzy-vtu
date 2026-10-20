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

        $rpId = strtolower(ltrim((string) config('passkeys.relying_party_id', ''), '.'));
        $origins = (array) config('passkeys.allowed_origins', []);
        abort_if($rpId === '' || $origins === [] || ! config('app.key'), 503, 'Passkey authentication is not configured.');

        foreach ($origins as $origin) {
            if (! is_string($origin) || $origin === '') {
                abort(503, 'Passkey authentication requires exact HTTPS origins.');
            }

            $parts = parse_url($origin);
            $host = strtolower((string) ($parts['host'] ?? ''));
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));

            // Browser Origin values have no path, query, fragment, credentials or wildcard.
            if ($scheme !== 'https' || $host === ''
                || isset($parts['path']) || isset($parts['query']) || isset($parts['fragment'])
                || isset($parts['user']) || isset($parts['pass'])
                || ($host !== $rpId && ! str_ends_with($host, '.'.$rpId))) {
                abort(503, 'Passkey origins must be exact HTTPS origins on the configured relying-party domain.');
            }
        }

        return $next($request);
    }
}
