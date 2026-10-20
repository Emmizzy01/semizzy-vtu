<?php

namespace App\\Services\\Security;

use App\\Models\\User;
use Illuminate\\Support\\Facades\\Http;
use Illuminate\\Support\\Facades\\Mail;
use RuntimeException;

class LoginOtpDeliveryService
{
    public function deliver(User $user, string $code, string $channel): void
    {
        if ($channel === 'email') {
            $mailer = (string) config('mail.default');
            if (in_array($mailer, ['log', 'array'], true)) {
                throw new RuntimeException('Email OTP requires a real configured mail transport.');
            }

            if (! $user->email) {
                throw new RuntimeException('This account has no email address for verification.');
            }

            Mail::to($user->email)->send(new \\App\\Mail\\LoginOtpMail($code));
            return;
        }

        if (! in_array($channel, ['sms', 'whatsapp'], true)) {
            throw new RuntimeException('The configured login OTP channel is not supported.');
        }

        if (! config("auth_security.{$channel}_otp_enabled", false)) {
            throw new RuntimeException('The configured phone OTP channel is disabled.');
        }

        $destination = $channel === 'whatsapp'
            ? (string) ($user->whatsapp_verified_at && $user->whatsapp_number ? $user->whatsapp_number : '')
            : (string) ($user->mobile_number ?? '');

        if ($destination === '') {
            throw new RuntimeException('This account has no eligible verified destination for the configured OTP channel.');
        }

        $endpoint = trim((string) config("services.login_otp.{$channel}.endpoint", ''));
        $token = trim((string) config("services.login_otp.{$channel}.token", ''));
        if ($endpoint === '' || $token === '' || ! filter_var($endpoint, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($endpoint), 'https://')) {
            throw new RuntimeException('The phone OTP provider is not fully configured for secure delivery.');
        }

        $response = Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout(3)
            ->timeout(8)
            ->post($endpoint, [
                'to' => $destination,
                'message' => "Your SEMIZZY sign-in code is {$code}. It expires soon. Do not share this code.",
                'purpose' => 'login_otp',
                'channel' => $channel,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('The phone OTP provider did not accept the delivery request.');
        }
    }
}
