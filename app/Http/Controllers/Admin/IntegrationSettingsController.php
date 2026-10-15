<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class IntegrationSettingsController extends Controller
{
    public function paymentGateway(Request $request, IntegrationSettings $settings): InertiaResponse
    {
        $secret = (string) $settings->get('paystack.secret_key', config('services.paystack.secret_key', ''));
        $callback = trim((string) $settings->get('paystack.callback_url', config('services.paystack.callback_url', '')));
        if ($callback === '') {
            $callback = $request->getSchemeAndHttpHost().'/wallet/funding/return';
        }
        $webhookUrl = $request->getSchemeAndHttpHost().'/api/payments/webhooks/paystack';

        $detectedMode = str_starts_with($secret, 'sk_test_') ? 'Test' : (str_starts_with($secret, 'sk_live_') ? 'Live' : 'Unknown');
        $savedMode = (string) $settings->get('paystack.mode', '');
        $mode = in_array($savedMode, ['test', 'live'], true) ? ucfirst($savedMode) : $detectedMode;

        return Inertia::render('Admin/PaymentGateway', ['gateway' => [
            'name' => 'Paystack', 'configured' => $secret !== '',
            'production' => app()->environment('production'),
            'callbackConfigured' => $callback !== '',
            'mode' => $mode,
            'keyMode' => $detectedMode,
            'callbackHost' => parse_url($callback, PHP_URL_HOST) ?: null,
            'callbackUrl' => $callback,
            'webhookUrl' => $webhookUrl,
        ]]);
    }

    public function updatePaymentGateway(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'secret_key' => ['nullable', 'string', 'max:500'],
            'mode' => ['required', 'in:test,live'],
            'callback_url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'confirm_live' => ['nullable', 'boolean'],
        ]);
        $secret = trim((string) ($data['secret_key'] ?? ''));
        if ($secret !== '' && ! preg_match('/^sk_(?:test|live)_[^\s]+$/', $secret)) {
            return back()->withErrors(['secret_key' => 'Enter a valid Paystack secret key beginning with sk_test_ or sk_live_.']);
        }
        $effectiveSecret = $secret !== ''
            ? $secret
            : (string) $settings->get('paystack.secret_key', config('services.paystack.secret_key', ''));
        $expectedPrefix = $data['mode'] === 'test' ? 'sk_test_' : 'sk_live_';
        if ($effectiveSecret !== '' && ! str_starts_with($effectiveSecret, $expectedPrefix)) {
            return back()->withErrors(['secret_key' => 'The selected '.strtoupper($data['mode']).' mode requires a matching Paystack '.($data['mode'] === 'test' ? 'test' : 'live').' secret key. Enter the matching key before switching modes.']);
        }
        if ($data['mode'] === 'live' && ! $request->boolean('confirm_live')) {
            return back()->withErrors(['confirm_live' => 'Confirm live payment mode before saving.']);
        }
        if ($secret !== '') {
            $settings->set('paystack.secret_key', $secret, $request->user()->id);
        }
        $settings->set('paystack.mode', $data['mode'], $request->user()->id);
        $settings->set('paystack.callback_url', $data['callback_url'], $request->user()->id);

        return back()->with('success', 'Payment gateway settings saved.');
    }

    public function apiProviders(IntegrationSettings $settings): InertiaResponse
    {
        $driver = (string) $settings->get('vtu.driver', config('services.vtu.driver', 'configured'));
        $baseUrl = (string) $settings->get('vtu.vtpass.base_url', config('services.vtu.vtpass.base_url', 'https://sandbox.vtpass.com/api'));
        $endpoint = (string) $settings->get('vtu.endpoint', config('services.vtu.endpoint', ''));
        $token = (string) $settings->get('vtu.token', config('services.vtu.token', ''));
        $apiKey = (string) $settings->get('vtu.vtpass.api_key', config('services.vtu.vtpass.api_key', ''));
        $secret = (string) $settings->get('vtu.vtpass.secret_key', config('services.vtu.vtpass.secret_key', ''));

        return Inertia::render('Admin/ApiProviders', ['provider' => [
            'driver' => $driver,
            'name' => (string) $settings->get('vtu.name', config('services.vtu.name', 'configured_vtu')),
            'genericEndpoint' => $endpoint, 'genericEndpointConfigured' => $endpoint !== '',
            'genericTokenConfigured' => $token !== '', 'vtpassSelected' => $driver === 'vtpass',
            'vtpassBaseUrl' => $baseUrl, 'vtpassApiKeyConfigured' => $apiKey !== '',
            'vtpassSecretKeyConfigured' => $secret !== '',
            'mode' => $baseUrl === 'https://sandbox.vtpass.com/api' ? 'Sandbox' : ($baseUrl === 'https://vtpass.com/api' ? 'Live' : 'Unrecognised URL'),
            'catalogCommand' => 'vtu:catalog-sync',
        ]]);
    }

    public function updateApiProviders(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'driver' => ['required', 'in:configured,vtpass'],
            'name' => ['required', 'string', 'max:100'],
            'generic_endpoint' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'generic_token' => ['nullable', 'string', 'max:1000'],
            'vtpass_base_url' => ['required', 'in:https://sandbox.vtpass.com/api,https://vtpass.com/api'],
            'vtpass_api_key' => ['nullable', 'string', 'max:1000'],
            'vtpass_secret_key' => ['nullable', 'string', 'max:1000'],
            'confirm_live' => ['nullable', 'boolean'],
        ]);
        if ($data['vtpass_base_url'] === 'https://vtpass.com/api' && ! $request->boolean('confirm_live')) {
            return back()->withErrors(['confirm_live' => 'Confirm live VTpass mode before saving.']);
        }
        $actor = $request->user()->id;
        $settings->set('vtu.driver', $data['driver'], $actor);
        $settings->set('vtu.name', trim($data['name']), $actor);
        $settings->set('vtu.vtpass.base_url', $data['vtpass_base_url'], $actor);
        foreach (['generic_endpoint' => 'vtu.endpoint', 'generic_token' => 'vtu.token', 'vtpass_api_key' => 'vtu.vtpass.api_key', 'vtpass_secret_key' => 'vtu.vtpass.secret_key'] as $field => $key) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value !== '') $settings->set($key, $value, $actor);
        }

        return back()->with('success', 'API provider settings saved.');
    }
}
