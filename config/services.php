<?php

return [
    // Keep each provider's secret separate; never commit secret values.
    'semizzy_payments' => [
        'webhook_secrets' => [
            'semizzy' => env('SEMIZZY_WEBHOOK_SECRET'),
        ],
        'signature_tolerance_seconds' => (int) env('SEMIZZY_WEBHOOK_TOLERANCE_SECONDS', 300),
        'intent_ttl_minutes' => (int) env('SEMIZZY_PAYMENT_INTENT_TTL_MINUTES', 30),
    ],
];