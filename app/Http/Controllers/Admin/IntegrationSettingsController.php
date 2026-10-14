<?php

namespace App\\Http\\Controllers\\Admin;

use App\\Http\\Controllers\\Controller;
use Illuminate\\Support\\Str;
use Inertia\\Inertia;
use Inertia\\Response as InertiaResponse;

class IntegrationSettingsController extends Controller
{
    public function paymentGateway(): InertiaResponse
    {
        $secret = (string) config('services.paystack.secret_key', '');
        $callback = (string) config('services.paystack.callback_url', '');

        return Inertia::render('Admin/PaymentGateway', [
            'gateway' => [
                'name' => 'Paystack',
                'configured' => $secret !== '',
                'callbackConfigured' => $callback !== '',
                'mode' => str_starts_with($secret, 'sk_test_')
                    ? 'Test'
                    : (str_starts_with($secret, 'sk_live_') ? 'Live' : 'Unknown'),
                'callbackHost' => $this->safeHost($callback),
                'webhookPath' => '/api/payments/webhooks/paystack',
            ],
        ]);
    }

    public function apiProviders(): InertiaResponse
    {
        $driver = (string) config('services.vtu.driver', 'configured');
        $baseUrl = rtrim((string) config('services.vtu.vtpass.base_url', ''), '/');
        $isVtpass = $driver === 'vtpass';
        $vtpassUrlIsSandbox = str_starts_with($baseUrl, 'https://sandbox.vtpass.com/');
        $vtpassUrlIsLive = $baseUrl === 'https://vtpass.com/api';

        return Inertia::render('Admin/ApiProviders', [
            'provider' => [
                'driver' => $driver,
                'name' => (string) config('services.vtu.name', 'configured_vtu'),
                'genericEndpointConfigured' => (string) config('services.vtu.endpoint', '') !== '',
                'genericTokenConfigured' => (string) config('services.vtu.token', '') !== '',
                'vtpassSelected' => $isVtpass,
                'vtpassBaseUrl' => $this->safeProviderUrl($baseUrl),
                'vtpassApiKeyConfigured' => (string) config('services.vtu.vtpass.api_key', '') !== '',
                'vtpassSecretKeyConfigured' => (string) config('services.vtu.vtpass.secret_key', '') !== '',
                'mode' => $vtpassUrlIsSandbox ? 'Sandbox' : ($vtpassUrlIsLive ? 'Live' : 'Unrecognised URL'),
                'catalogCommand' => 'vtu:catalog-sync',
            ],
        ]);
    }

    private function safeHost(string $url): ?string
    {
        if ($url === '' || ! Str::startsWith($url, ['https://'])) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : null;
    }

    private function safeProviderUrl(string $url): ?string
    {
        if (in_array($url, ['https://sandbox.vtpass.com/api', 'https://vtpass.com/api'], true)) {
            return $url;
        }

        return null;
    }
}
