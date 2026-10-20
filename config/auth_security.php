<?php

return [
    // Select one channel only. Phone channels remain off until an HTTPS provider contract is configured and verified.
    'login_otp_channel' => env('AUTH_LOGIN_OTP_CHANNEL', 'email'),
    'email_otp_enabled' => (bool) env('AUTH_LOGIN_EMAIL_OTP_ENABLED', false),
    'sms_otp_enabled' => (bool) env('AUTH_LOGIN_SMS_OTP_ENABLED', false),
    'whatsapp_otp_enabled' => (bool) env('AUTH_LOGIN_WHATSAPP_OTP_ENABLED', false),
    'email_otp_ttl_minutes' => (int) env('AUTH_LOGIN_EMAIL_OTP_TTL_MINUTES', 10),
    'email_otp_max_attempts' => (int) env('AUTH_LOGIN_EMAIL_OTP_MAX_ATTEMPTS', 5),
];
