<?php

return [
    // Set a long random secret in the deployment environment. Never commit secrets.
    'semizzy_payments' => [
        'webhook_secret' => env('SEMIZZY_WEBHOOK_SECRET'),
        'signature_tolerance_seconds' => (int) env('SEMIZZY_WEBHOOK_TOLERANCE_SECONDS', 300),
        'intent_ttl_minutes' => (int) env('SEMIZZY_PAYMENT_INTENT_TTL_MINUTES', 30),
    ],
];