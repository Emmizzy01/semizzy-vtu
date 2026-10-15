<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformFeatureControl;
use App\Models\PlatformSettingAudit;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ManualDepositSettingsController extends Controller
{
    public function edit(IntegrationSettings $settings): InertiaResponse
    {
        return Inertia::render('Admin/ManualDeposits/Settings', [
            'settings' => [
                'enabled' => filter_var($settings->get('manual_deposit.enabled', true), FILTER_VALIDATE_BOOLEAN),
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

        DB::transaction(function () use ($request, $settings, $data): void {
            $defaults = [
                'enabled' => '1', 'bank_name' => '', 'account_name' => '', 'account_number' => '',
                'instructions' => 'Transfer the exact amount and submit the receipt/reference. Funds are credited only after verification.',
            ];
            foreach (['enabled', 'bank_name', 'account_name', 'account_number', 'instructions'] as $key) {
                $old = (string) $settings->get('manual_deposit.'.$key, $defaults[$key]);
                $new = $key === 'enabled' ? ($data[$key] ? '1' : '0') : trim((string) ($data[$key] ?? ''));
                if ($old !== $new) {
                    $settings->set('manual_deposit.'.$key, $new, (int) $request->user()->id);
                    PlatformSettingAudit::query()->create([
                        'actor_user_id' => $request->user()->id, 'setting_key' => 'manual_deposit.'.$key,
                        'old_value_hash' => hash('sha256', $old), 'new_value_hash' => hash('sha256', $new),
                        'reason' => trim($data['reason']), 'created_at' => now(),
                    ]);
                }
            }
            $feature = PlatformFeatureControl::query()->where('feature_key', 'service.manual_deposit')->lockForUpdate()->first();
            if ($feature && $feature->is_enabled !== (bool) $data['enabled']) {
                $old = $feature->is_enabled ? '1' : '0';
                $new = $data['enabled'] ? '1' : '0';
                $feature->forceFill(['is_enabled' => (bool) $data['enabled'], 'updated_by_user_id' => $request->user()->id])->save();
                PlatformSettingAudit::query()->create([
                    'actor_user_id' => $request->user()->id, 'setting_key' => 'feature.service.manual_deposit',
                    'old_value_hash' => hash('sha256', $old), 'new_value_hash' => hash('sha256', $new),
                    'reason' => trim($data['reason']), 'created_at' => now(),
                ]);
            }
        }, attempts: 3);
        return back()->with('status', 'Manual deposit instructions and availability updated.');
    }
}
