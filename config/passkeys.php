<?php

use App\Http\Middleware\EnsurePasskeysEnabled;
use App\Http\Middleware\EnsureRecentPasskeyConfirmation;

return [
    'enabled' => (bool) env('PASSKEYS_ENABLED', false),
    'relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID') ?: (parse_url(config('app.url'), PHP_URL_HOST) ?: 'localhost'),
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PASSKEYS_ALLOWED_ORIGINS', config('app.url')))
    ))),
    'user_handle_secret' => env('PASSKEYS_USER_HANDLE_SECRET', config('app.key')),
    'timeout' => 60000,
    'guard' => 'web',
    'middleware' => ['web', EnsurePasskeysEnabled::class],
    'management_middleware' => ['auth', EnsurePasskeysEnabled::class, EnsureRecentPasskeyConfirmation::class],
    'throttle' => 'throttle:6,1',
    'redirect' => '/dashboard',
];
