<?php

return [
    // Keep each provider's secret separate; never commit secret values.
    'vtu' => [
        'driver' => env('VTU_PROVIDER_DRIVER', 'vtpass'),
        'name' => env('VTU_PROVIDER_NAME', 'configured_vtu'),
        'endpoint' => env('VTU_PROVIDER_ENDPOINT'),
        'token' => env('VTU_PROVIDER_TOKEN'),
        'stuck_order_minutes' => (int) env('VTU_STUCK_ORDER_MINUTES', 60),
        // Multi-provider routing is deliberately opt-in until adapters, account credentials,
        // live catalogue mappings, webhook handling, and failover tests pass release gates.
        'multi_provider' => [
            'enabled' => (bool) env('VTU_MULTI_PROVIDER_ENABLED', false),
            // Hard-off for this release by operator decision: no environment value may enable post-submit failover.
            'failover_enabled' => false,
            'priority' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('VTU_PROVIDER_PRIORITY', 'sogo,gsubz,dataway')),
            ))),
            // Empty by default: only providers whose final non-delivery contract
            // has been reviewed and tested may be enabled for automatic failover.
            'definitive_failure_providers' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env('VTU_DEFINITIVE_FAILURE_PROVIDERS', '')),
            ))),
        ],
        'sogo' => [
            'base_url' => env('VTU_SOGO_BASE_URL', env('APP_ENV', 'production') === 'production'
                ? 'https://api.sogo.africa/v1'
                : 'https://sandbox.sogo.africa/v1'),
            'api_key' => env('VTU_SOGO_API_KEY'),
            'webhook_secret' => env('VTU_SOGO_WEBHOOK_SECRET'),
        ],
        'gsubz' => [
            'base_url' => env('VTU_GSUBZ_BASE_URL', 'https://api.gsubz.com'),
            'api_key' => env('VTU_GSUBZ_API_KEY'),
            'webhook_secret' => env('VTU_GSUBZ_WEBHOOK_SECRET'),
        ],
        'dataway' => [
            'base_url' => env('VTU_DATAWAY_BASE_URL', 'https://dataway.ng/api/v1/merchant'),
            'api_key' => env('VTU_DATAWAY_API_KEY'),
            'webhook_secret' => env('VTU_DATAWAY_WEBHOOK_SECRET'),
        ],
        // Whole-naira airtime face values; data prices always come from VTpass variations.
        'airtime_denominations' => array_values(array_filter(array_map('intval', explode(',', (string) env('VTU_AIRTIME_DENOMINATIONS', '100,200,500,1000'))))),
        'vtpass' => [
            'base_url' => env('VTU_VTPASS_BASE_URL', env('APP_ENV', 'production') === 'production' ? 'https://vtpass.com/api' : 'https://sandbox.vtpass.com/api'),
            'api_key' => env('VTU_VTPASS_API_KEY'),
            'secret_key' => env('VTU_VTPASS_SECRET_KEY'),
        // Hard isolation gate: keep new purchases disabled until technical support confirms recovery semantics.
        'purchase_isolated' => true,
        ],
    ],
    // Education adapters are fail-closed by default. Enable only after merchant access,
    // product mapping, validation and recovery contracts are independently verified.
    'education' => [
        'providers' => [
            'sogo' => [
                'enabled' => (bool) env('EDUCATION_SOGO_ENABLED', false),
                'base_url' => env('EDUCATION_SOGO_BASE_URL', env('APP_ENV', 'production') === 'production'
                    ? 'https://api.sogo.africa/v1'
                    : 'https://sandbox.sogo.africa/v1'),
                'api_key' => env('EDUCATION_SOGO_API_KEY'),
            ],
            'gsubz' => [
                'enabled' => (bool) env('EDUCATION_GSUBZ_ENABLED', false),
                'base_url' => env('EDUCATION_GSUBZ_BASE_URL', 'https://api.gsubz.com'),
                'api_key' => env('EDUCATION_GSUBZ_API_KEY'),
            ],
            'vtpass' => [
                'enabled' => (bool) env('EDUCATION_VTPASS_ENABLED', false),
                'base_url' => env('EDUCATION_VTPASS_BASE_URL', env('APP_ENV', 'production') === 'production'
                    ? 'https://vtpass.com/api'
                    : 'https://sandbox.vtpass.com/api'),
                'api_key' => env('EDUCATION_VTPASS_API_KEY'),
                'secret_key' => env('EDUCATION_VTPASS_SECRET_KEY'),
            ],
            'dataway' => [
                'enabled' => false,
                'base_url' => env('EDUCATION_DATAWAY_BASE_URL'),
                'api_key' => env('EDUCATION_DATAWAY_API_KEY'),
            ],
        ],
    ],
    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    ],
    'payments' => [
        'reconciliation_grace_minutes' => (int) env('PAYMENTS_RECONCILIATION_GRACE_MINUTES', 5),
        'refund_stale_minutes' => (int) env('PAYMENTS_REFUND_STALE_MINUTES', 15),
        'alert_webhook_url' => env('PAYMENTS_ALERT_WEBHOOK_URL'),
    ],
    'semizzy_payments' => [
        'webhook_secrets' => [
            'semizzy' => env('SEMIZZY_WEBHOOK_SECRET'),
        ],
        'signature_tolerance_seconds' => (int) env('SEMIZZY_WEBHOOK_TOLERANCE_SECONDS', 300),
        'intent_ttl_minutes' => (int) env('SEMIZZY_PAYMENT_INTENT_TTL_MINUTES', 30),
        // Hard-off until funding retry, webhook concurrency, and full CI release checks pass.
        'live_funding_enabled' => (bool) env('SEMIZZY_LIVE_FUNDING_ENABLED', false),
    ],
];