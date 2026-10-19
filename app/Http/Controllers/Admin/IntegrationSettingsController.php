<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        return Inertia::render('Admin/PaymentGateway', [
            'manualDeposit' => [
                'enabled' => filter_var($settings->get('manual_deposit.enabled', false), FILTER_VALIDATE_BOOLEAN),
                'bank_name' => (string) $settings->get('manual_deposit.bank_name', ''),
                'account_name' => (string) $settings->get('manual_deposit.account_name', ''),
                'account_number' => (string) $settings->get('manual_deposit.account_number', ''),
                'instructions' => (string) $settings->get('manual_deposit.instructions', 'Transfer the exact amount and submit the receipt/reference. Funds are credited only after verification.'),
            ],
            'gateway' => [
            'name' => 'Paystack', 'configured' => $secret !== '',
            'transfersEnabled' => filter_var($settings->get('paystack.transfers_enabled', false), FILTER_VALIDATE_BOOLEAN),
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
            'transfers_enabled' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $secret = trim((string) ($data['secret_key'] ?? ''));
        if ($secret !== '' && ! preg_match('/^sk_(?:test|live)_[^\s]+$/', $secret)) {
            return back()->withErrors(['secret_key' => 'Enter a valid Paystack secret key beginning with sk_test_ or sk_live_.']);
        }
        $effectiveSecret = $secret !== ''
            ? $secret
            : (string) $settings->get('paystack.secret_key', config('services.paystack.secret_key', ''));
        if ($data['mode'] === 'live' && $effectiveSecret === '') {
            return back()->withErrors(['secret_key' => 'Configure a Paystack live secret key before enabling live payment mode.']);
        }
        $expectedPrefix = $data['mode'] === 'test' ? 'sk_test_' : 'sk_live_';
        if ($effectiveSecret !== '' && ! str_starts_with($effectiveSecret, $expectedPrefix)) {
            return back()->withErrors(['secret_key' => 'The selected '.strtoupper($data['mode']).' mode requires a matching Paystack '.($data['mode'] === 'test' ? 'test' : 'live').' secret key. Enter the matching key before switching modes.']);
        }
        if ($data['mode'] === 'live' && ! $request->boolean('confirm_live')) {
            return back()->withErrors(['confirm_live' => 'Confirm live payment mode before saving.']);
        }
        $actor = (int) $request->user()->id;
        $reason = trim($data['reason']);
        DB::transaction(function () use ($settings, $actor, $reason, $data, $secret): void {
            $updates = [
                'paystack.mode' => $data['mode'],
                'paystack.callback_url' => trim($data['callback_url']),
                'paystack.transfers_enabled' => ! empty($data['transfers_enabled']) ? '1' : '0',
            ];
            if ($secret !== '') $updates['paystack.secret_key'] = $secret;

            foreach ($updates as $key => $newValue) {
                $fallback = $key === 'paystack.secret_key'
                    ? (string) config('services.paystack.secret_key', '')
                    : ($key === 'paystack.callback_url' ? (string) config('services.paystack.callback_url', '') : ($key === 'paystack.mode' ? 'test' : '0'));
                $oldValue = (string) $settings->get($key, $fallback);
                $storedSettingExists = \App\Models\IntegrationSetting::query()->where('key', $key)->exists();
                if ($storedSettingExists && hash_equals(hash('sha256', $oldValue), hash('sha256', (string) $newValue))) continue;

                $settings->set($key, (string) $newValue, $actor);
                PlatformSettingAudit::query()->create([
                    'actor_user_id' => $actor,
                    'setting_key' => $key,
                    'old_value_hash' => hash('sha256', $oldValue),
                    'new_value_hash' => hash('sha256', (string) $newValue),
                    'reason' => $reason,
                    'created_at' => now(),
                ]);
            }
        }, attempts: 3);

        return back()->with('success', 'Payment gateway settings saved and audited.');
    }

    public function apiProviders(IntegrationSettings $settings): InertiaResponse
    {
        $providers = [];
        $endpoints = [
            'sogo' => ['https://sandbox.sogo.africa/v1', 'https://api.sogo.africa/v1'],
            'gsubz' => ['https://api.gsubz.com'],
            'dataway' => ['https://dataway.ng/api/v1/merchant'],
            'vtpass' => ['https://sandbox.vtpass.com/api', 'https://vtpass.com/api'],
        ];
        foreach (['sogo' => 'Sogo Africa', 'gsubz' => 'GSUBZ', 'dataway' => 'Dataway', 'vtpass' => 'VTpass'] as $key => $label) {
            $base = (string) $settings->get("vtu.{$key}.base_url", config("services.vtu.{$key}.base_url", ''));
            $apiKey = (string) $settings->get("vtu.{$key}.api_key", config("services.vtu.{$key}.api_key", ''));
            $secretConfigured = $apiKey !== '' && parse_url($base, PHP_URL_SCHEME) === 'https';
            $providers[] = [
                'key' => $key,
                'name' => $label,
                'configured' => $secretConfigured,
                'apiKeyConfigured' => $apiKey !== '',
                'environment' => str_contains(strtolower($base), 'sandbox') ? 'Sandbox' : 'Live endpoint',
                'baseUrl' => $base,
                'baseHost' => parse_url($base, PHP_URL_HOST) ?: null,
                'allowedBaseUrls' => $endpoints[$key],
                'catalogueImportSupported' => in_array($key, ['sogo', 'gsubz', 'dataway'], true),
                'purchaseRoutingEnabled' => $key !== 'vtpass'
                    && (bool) config('services.vtu.multi_provider.enabled', false)
                    && $key !== 'dataway',
                'purchaseHold' => $key === 'vtpass' || $key === 'dataway',
            ];
        }

        return Inertia::render('Admin/ApiProviders', [
            'providers' => $providers,
            'failoverEnabled' => (bool) config('services.vtu.multi_provider.failover_enabled', false),
            'routingEnabled' => (bool) config('services.vtu.multi_provider.enabled', false),
            'priority' => config('services.vtu.multi_provider.priority', ['sogo', 'gsubz', 'dataway']),
        ]);
    }

    public function saveProviderCredentials(Request $request, string $provider, IntegrationSettings $settings): RedirectResponse
    {
        abort_unless(in_array($provider, ['sogo', 'gsubz', 'dataway'], true), 404);

        $data = $request->validate([
            'base_url' => ['required', 'url', 'starts_with:https://', 'max:2048'],
            'api_key' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $allowed = [
            'sogo' => ['https://sandbox.sogo.africa/v1', 'https://api.sogo.africa/v1'],
            'gsubz' => ['https://api.gsubz.com'],
            'dataway' => ['https://dataway.ng/api/v1/merchant'],
        ];
        $base = rtrim(trim($data['base_url']), '/');
        if (! in_array($base, $allowed[$provider], true)) {
            return back()->withErrors(['base_url' => 'Choose an official HTTPS endpoint for this provider.']);
        }

        $key = trim((string) ($data['api_key'] ?? ''));
        $existingKey = (string) $settings->get("vtu.{$provider}.api_key", config("services.vtu.{$provider}.api_key", ''));
        $effectiveKey = $key !== '' ? $key : $existingKey;
        if ($effectiveKey === '') {
            return back()->withErrors(['api_key' => 'Enter the provider API key before saving or importing a catalogue.']);
        }
        if ($provider === 'sogo' && str_contains($base, 'sandbox.sogo.africa') && ! str_starts_with($effectiveKey, 'sogo_sk_test_')) {
            return back()->withErrors(['api_key' => 'The Sogo sandbox endpoint requires a sandbox secret key.']);
        }
        if ($provider === 'sogo' && str_contains($base, 'api.sogo.africa') && ! str_starts_with($effectiveKey, 'sogo_sk_live_')) {
            return back()->withErrors(['api_key' => 'The Sogo live endpoint requires a live secret key.']);
        }
        if ($provider === 'gsubz' && ! str_starts_with($effectiveKey, 'ap_')) {
            return back()->withErrors(['api_key' => 'Enter a GSUBZ API key beginning with ap_.']);
        }
        if ($provider === 'dataway' && ! str_starts_with($effectiveKey, 'bp_live_')) {
            return back()->withErrors(['api_key' => 'Enter the Dataway Merchant API key beginning with bp_live_.']);
        }

        $actor = (int) $request->user()->id;
        $reason = trim($data['reason']);
        DB::transaction(function () use ($settings, $provider, $base, $key, $existingKey, $effectiveKey, $actor, $reason): void {
            $updates = ["vtu.{$provider}.base_url" => $base];
            if ($key !== '') $updates["vtu.{$provider}.api_key"] = $key;
            foreach ($updates as $settingKey => $newValue) {
                $fallback = str_ends_with($settingKey, '.base_url')
                    ? (string) config("services.vtu.{$provider}.base_url", '')
                    : (string) config("services.vtu.{$provider}.api_key", '');
                $oldValue = (string) $settings->get($settingKey, $fallback);
                if (hash_equals(hash('sha256', $oldValue), hash('sha256', (string) $newValue))) continue;
                $settings->set($settingKey, (string) $newValue, $actor);
                PlatformSettingAudit::query()->create([
                    'actor_user_id' => $actor,
                    'setting_key' => $settingKey,
                    'old_value_hash' => hash('sha256', $oldValue),
                    'new_value_hash' => hash('sha256', (string) $newValue),
                    'reason' => $reason,
                    'created_at' => now(),
                ]);
            }
        }, attempts: 3);

        return back()->with('success', strtoupper($provider).' credentials saved securely. Catalogue import is enabled; customer purchase routing remains separately gated.');
    }

    public function updateApiProviders(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        if ((string) $settings->get('vtu.driver', config('services.vtu.driver', 'configured')) === 'vtpass') {
            return back()->withErrors([
                'provider' => 'Provider configuration is temporarily locked pending technical review. Existing orders remain available for reconciliation.',
            ]);
        }

        $data = $request->validate([
            'driver' => ['required', 'in:configured'],
            'name' => ['required', 'string', 'max:100'],
            'generic_endpoint' => ['nullable', 'url', 'starts_with:https://', 'max:2048'],
            'generic_token' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $actor = (int) $request->user()->id;
        $reason = trim($data['reason']);
        DB::transaction(function () use ($settings, $actor, $reason, $data): void {
            $updates = [
                'vtu.driver' => 'configured',
                'vtu.name' => trim($data['name']),
            ];
            $endpoint = trim((string) ($data['generic_endpoint'] ?? ''));
            if ($endpoint !== '') {
                $updates['vtu.endpoint'] = $endpoint;
            }
            $token = trim((string) ($data['generic_token'] ?? ''));
            if ($token !== '') {
                $updates['vtu.token'] = $token;
            }

            foreach ($updates as $key => $newValue) {
                $fallback = match ($key) {
                    'vtu.driver' => (string) config('services.vtu.driver', 'configured'),
                    'vtu.name' => (string) config('services.vtu.name', 'configured_vtu'),
                    'vtu.endpoint' => (string) config('services.vtu.endpoint', ''),
                    'vtu.token' => (string) config('services.vtu.token', ''),
                    default => '',
                };
                $storedSettingExists = \App\Models\IntegrationSetting::query()->where('key', $key)->exists();
                $oldValue = (string) $settings->get($key, $fallback);
                if ($storedSettingExists && hash_equals(hash('sha256', $oldValue), hash('sha256', (string) $newValue))) {
                    continue;
                }

                $settings->set($key, (string) $newValue, $actor);
                PlatformSettingAudit::query()->create([
                    'actor_user_id' => $actor,
                    'setting_key' => $key,
                    'old_value_hash' => hash('sha256', $oldValue),
                    'new_value_hash' => hash('sha256', (string) $newValue),
                    'reason' => $reason,
                    'created_at' => now(),
                ]);
            }
        }, attempts: 3);

        return back()->with('success', 'API provider settings saved and audited.');
    }
}
