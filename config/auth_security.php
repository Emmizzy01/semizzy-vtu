<?php

return [
    // Keep disabled until a real, tested outbound mail provider is configured.
    'email_otp_enabled' => (bool) env('AUTH_LOGIN_EMAIL_OTP_ENABLED', false),
    'email_otp_ttl_minutes' => (int) env('AUTH_LOGIN_EMAIL_OTP_TTL_MINUTES', 10),
    'email_otp_max_attempts' => (int) env('AUTH_LOGIN_EMAIL_OTP_MAX_ATTEMPTS', 5),
];
