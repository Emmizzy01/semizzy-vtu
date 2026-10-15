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
            'theme_skin' => (string) $settings->get('site.theme_skin', 'emerald'),
            'maintenance_mode' => filter_var($settings->get('site.maintenance_mode', false), FILTER_VALIDATE_BOOLEAN),
            'airtime_data_enabled' => filter_var($settings->get('service.airtime_data.enabled', true), FILTER_VALIDATE_BOOLEAN),
            'manual_deposit_enabled' => filter_var($settings->get('manual_deposit.enabled', true), FILTER_VALIDATE_BOOLEAN),
        ]]);
    }

    public function update(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate([
            'site_name' => ['required', 'string', 'min:2', 'max:120'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9() .-]*$/'],
            'theme_skin' => ['required', 'in:emerald,blue,violet,teal,amber'],
            'maintenance_mode' => ['required', 'boolean'],
            'airtime_data_enabled' => ['required', 'boolean'],
            'manual_deposit_enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $map = [
            'site_name' => 'site.name', 'support_email' => 'site.support_email',
            'support_phone' => 'site.support_phone', 'theme_skin' => 'site.theme_skin', 'maintenance_mode' => 'site.maintenance_mode',
            'airtime_data_enabled' => 'service.airtime_data.enabled', 'manual_deposit_enabled' => 'manual_deposit.enabled',
        ];
        DB::transaction(function () use ($request, $settings, $data, $map): void {
            foreach ($map as $field => $key) {
                $fallback = match ($field) {
                    'site_name' => config('app.name', 'Semizzy'),
                    'theme_skin' => 'emerald',
                    'maintenance_mode' => '0',
                    'airtime_data_enabled', 'manual_deposit_enabled' => '1',
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
}
