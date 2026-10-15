<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ManualDepositSettingsController extends Controller
{
    public function edit(IntegrationSettings $settings): InertiaResponse
    {
        return Inertia::render('Admin/ManualDeposits/Settings', [
            'settings' => [
                'enabled' => (bool) $settings->get('manual_deposit.enabled', true),
                'bank_name' => (string) $settings->get('manual_deposit.bank_name', ''),
                'account_name' => (string) $settings->get('manual_deposit.account_name', ''),
                'account_number' => (string) $settings->get('manual_deposit.account_number', ''),
                'instructions' => (string) $settings->get('manual_deposit.instructions', 'Transfer the exact amount and submit the receipt/reference. Funds are credited only after verification.'),
            ],
        ]);
    }

    public function update(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'bank_name' => ['nullable', 'string', 'max:120'],
            'account_name' => ['nullable', 'string', 'max:160'],
            'account_number' => ['nullable', 'string', 'max:40'],
            'instructions' => ['required', 'string', 'min:10', 'max:1500'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if ($data['enabled'] && (trim((string) ($data['bank_name'] ?? '')) === '' || trim((string) ($data['account_name'] ?? '')) === '' || trim((string) ($data['account_number'] ?? '')) === '')) {
            return back()->withErrors(['bank_name' => 'Bank name, account name and account number are required before enabling manual deposits.']);
        }

        foreach (['enabled', 'bank_name', 'account_name', 'account_number', 'instructions'] as $key) {
            $settings->set('manual_deposit.'.$key, $key === 'enabled' ? ($data[$key] ? '1' : '0') : trim((string) ($data[$key] ?? '')), $request->user()->id);
        }
        IntegrationSetting::query()->updateOrCreate(
            ['key' => 'platform.audit.last_manual_deposit_settings_reason'],
            ['value' => $data['reason'], 'updated_by_user_id' => $request->user()->id],
        );
        return back()->with('status', 'Manual deposit instructions and availability updated.');
    }
}
