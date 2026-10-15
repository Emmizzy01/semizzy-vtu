<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Models\PlatformFeatureControl;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class PlatformSettingsController extends Controller
{
    public function edit(IntegrationSettings $settings): InertiaResponse
    {
        return Inertia::render('Admin/PlatformSettings', ['settings' => [
            'site_name' => (string) $settings->get('site.name', config('app.name', 'Semizzy')),
            'support_email' => (string) $settings->get('site.support_email', ''),
            'support_phone' => (string) $settings->get('site.support_phone', ''),
            'theme_mode' => in_array((string) $settings->get('site.theme_mode', 'light'), ['light', 'dark'], true) ? (string) $settings->get('site.theme_mode', 'light') : 'light',
            'theme_skin' => match ((string) $settings->get('site.theme_skin', 'modern-green')) { 'emerald' => 'modern-green', 'teal' => 'navy-teal', 'blue' => 'minimal-blue', 'violet' => 'obsidian-luxe', 'amber' => 'arctic-light', default => (string) $settings->get('site.theme_skin', 'modern-green') },
            'maintenance_mode' => filter_var($settings->get('site.maintenance_mode', false), FILTER_VALIDATE_BOOLEAN),
            'airtime_data_enabled' => filter_var($settings->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN),
            'manual_deposit_enabled' => filter_var($settings->get('manual_deposit.enabled', false), FILTER_VALIDATE_BOOLEAN),
        ]]);
    }

    public function update(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'min:2', 'max:120'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9() .-]*$/'],
            'theme_skin' => ['required', 'in:modern-green,navy-teal,minimal-blue,obsidian-luxe,arctic-light'],
            'theme_mode' => ['required', 'in:light,dark'],
            'maintenance_mode' => ['required', 'boolean'],
            'airtime_data_enabled' => ['required', 'boolean'],
            'manual_deposit_enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        if ($data['manual_deposit_enabled'] && (
            trim((string) $settings->get('manual_deposit.bank_name', '')) === ''
            || trim((string) $settings->get('manual_deposit.account_name', '')) === ''
            || trim((string) $settings->get('manual_deposit.account_number', '')) === ''
        )) {
            return back()->withErrors(['manual_deposit_enabled' => 'Configure the manual deposit bank name, account name and account number in Manual deposit settings before enabling this service.']);
        }

        $map = [
            'site_name' => 'site.name', 'support_email' => 'site.support_email',
            'support_phone' => 'site.support_phone', 'theme_skin' => 'site.theme_skin', 'theme_mode' => 'site.theme_mode', 'maintenance_mode' => 'site.maintenance_mode',
            'airtime_data_enabled' => 'service.airtime_data.enabled', 'manual_deposit_enabled' => 'manual_deposit.enabled',
        ];
        DB::transaction(function () use ($request, $settings, $data, $map): void {
            foreach ($map as $field => $key) {
                $fallback = match ($field) {
                    'site_name' => config('app.name', 'Semizzy'),
                    'theme_skin' => 'modern-green',
                    'theme_mode' => 'light',
                    'maintenance_mode' => '0',
                    'airtime_data_enabled' => '1',
                    'manual_deposit_enabled' => '0',
                    default => '',
                };
                $old = (string) $settings->get($key, $fallback);
                $new = in_array($field, ['maintenance_mode', 'airtime_data_enabled', 'manual_deposit_enabled'], true)
                    ? ($data[$field] ? '1' : '0') : trim((string) ($data[$field] ?? ''));
                if (! hash_equals(hash('sha256', $old), hash('sha256', $new))) {
                    $settings->set($key, $new, (int) $request->user()->id);
                    PlatformSettingAudit::query()->create([
                        'actor_user_id' => $request->user()->id, 'setting_key' => $key,
                        'old_value_hash' => hash('sha256', $old), 'new_value_hash' => hash('sha256', $new),
                        'reason' => trim($data['reason']), 'created_at' => now(),
                    ]);
                }
                $featureKey = match ($field) {
                    'airtime_data_enabled' => 'service.airtime_data',
                    'manual_deposit_enabled' => 'service.manual_deposit',
                    default => null,
                };
                if ($featureKey !== null) {
                    $feature = PlatformFeatureControl::query()->where('feature_key', $featureKey)->lockForUpdate()->first();
                    if ($feature && $feature->is_enabled !== ($new === '1')) {
                        $oldFeatureValue = $feature->is_enabled ? '1' : '0';
                        $feature->forceFill(['is_enabled' => $new === '1', 'updated_by_user_id' => $request->user()->id])->save();
                        PlatformSettingAudit::query()->create([
                            'actor_user_id' => $request->user()->id, 'setting_key' => 'feature.'.$featureKey,
                            'old_value_hash' => hash('sha256', $oldFeatureValue), 'new_value_hash' => hash('sha256', $new),
                            'reason' => trim($data['reason']), 'created_at' => now(),
                        ]);
                    }
                }
            }
        }, attempts: 3);
        return back()->with('status', 'Platform settings saved and changes recorded in the audit trail.');
    }

    public function updateTheme(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate(['theme_mode' => ['required', 'in:light,dark']]);
        $new = $data['theme_mode'];
        $old = (string) $settings->get('site.theme_mode', 'light');
        if (! in_array($old, ['light', 'dark'], true)) { $old = 'light'; }
        if ($old !== $new) {
            DB::transaction(function () use ($request, $settings, $old, $new): void {
                $settings->set('site.theme_mode', $new, (int) $request->user()->id);
                PlatformSettingAudit::query()->create([
                    'actor_user_id' => $request->user()->id, 'setting_key' => 'site.theme_mode',
                    'old_value_hash' => hash('sha256', $old), 'new_value_hash' => hash('sha256', $new),
                    'reason' => 'Admin changed the global day/night appearance from the dashboard header.',
                    'created_at' => now(),
                ]);
            }, attempts: 3);
        }
        return back()->with('status', 'Global day/night appearance updated.');
    }
}
