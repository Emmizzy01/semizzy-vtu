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
            'reason' => ['required', 'string', 'min:8', 'max:500'],
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
        $actor = (int) $request->user()->id;
        $reason = trim($data['reason']);
        DB::transaction(function () use ($settings, $actor, $reason, $data, $secret): void {
            $updates = [
                'paystack.mode' => $data['mode'],
                'paystack.callback_url' => trim($data['callback_url']),
            ];
            if ($secret !== '') $updates['paystack.secret_key'] = $secret;

            foreach ($updates as $key => $newValue) {
                $fallback = $key === 'paystack.secret_key'
                    ? (string) config('services.paystack.secret_key', '')
                    : ($key === 'paystack.callback_url' ? (string) config('services.paystack.callback_url', '') : 'test');
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
        $driver = (string) $settings->get('vtu.driver', config('services.vtu.driver', 'configured'));

        // Do not expose provider identity, endpoint, environment, or credential status during a technical hold.
        if ($driver === 'vtpass') {
            return Inertia::render('Admin/ApiProviders', [
                'provider' => ['isolated' => true],
            ]);
        }

        $endpoint = (string) $settings->get('vtu.endpoint', config('services.vtu.endpoint', ''));
        $token = (string) $settings->get('vtu.token', config('services.vtu.token', ''));

        return Inertia::render('Admin/ApiProviders', ['provider' => [
            'isolated' => false,
            'name' => (string) $settings->get('vtu.name', config('services.vtu.name', 'configured_vtu')),
            'genericEndpoint' => $endpoint,
            'genericEndpointConfigured' => $endpoint !== '',
            'genericTokenConfigured' => $token !== '',
            'catalogCommand' => 'vtu:catalog-sync',
        ]]);
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
