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

class FeatureControlController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('Admin/FeatureControls', [
            'features' => PlatformFeatureControl::query()->orderBy('category')->orderBy('label')->get()->map(fn (PlatformFeatureControl $feature): array => [
                'feature_key' => $feature->feature_key, 'label' => $feature->label, 'category' => $feature->category,
                'owner' => $feature->owner, 'description' => $feature->description, 'dependencies' => $feature->dependencies ?? [],
                'default_enabled' => $feature->default_enabled, 'is_enabled' => $feature->is_enabled,
                'is_core' => $feature->is_core, 'is_implemented' => $feature->is_implemented,
            ])->values(),
        ]);
    }

    public function update(Request $request, string $featureKey, IntegrationSettings $settings): RedirectResponse
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:8', 'max:500']]);
        DB::transaction(function () use ($request, $featureKey, $data, $settings): void {
            $feature = PlatformFeatureControl::query()->where('feature_key', $featureKey)->lockForUpdate()->firstOrFail();
            abort_if($feature->is_core || ! $feature->is_implemented, 422, 'Core and roadmap-only features cannot be toggled here.');
            $enabled = (bool) $data['is_enabled'];
            $all = PlatformFeatureControl::query()->get()->keyBy('feature_key');
            if ($enabled) {
                foreach (($feature->dependencies ?? []) as $dependency) {
                    if (! isset($all[$dependency]) || ! $all[$dependency]->is_enabled) {
                        abort(422, 'Enable required dependency '.$dependency.' before enabling '.$feature->label.'.');
                    }
                }
            } else {
                $dependents = $all->filter(fn (PlatformFeatureControl $candidate): bool => $candidate->is_enabled && in_array($featureKey, $candidate->dependencies ?? [], true));
                if ($dependents->isNotEmpty()) abort(422, 'Disable dependent features first: '.$dependents->pluck('feature_key')->implode(', '));
            }
            $oldValue = $feature->is_enabled ? '1' : '0';
            $newValue = $enabled ? '1' : '0';
            if ($oldValue === $newValue) return;
            if ($featureKey === 'service.manual_deposit' && $enabled) {
                foreach (['bank_name', 'account_name', 'account_number'] as $requiredSetting) {
                    if (trim((string) $settings->get('manual_deposit.'.$requiredSetting, '')) === '') {
                        abort(422, 'Configure manual deposit bank name, account name and account number before enabling this service.');
                    }
                }
            }
            $feature->forceFill(['is_enabled' => $enabled, 'updated_by_user_id' => $request->user()->id])->save();
            if ($featureKey === 'service.airtime_data') $settings->set('service.airtime_data.enabled', $newValue, (int) $request->user()->id);
            if ($featureKey === 'service.manual_deposit') $settings->set('manual_deposit.enabled', $newValue, (int) $request->user()->id);
            PlatformSettingAudit::query()->create([
                'actor_user_id' => $request->user()->id, 'setting_key' => 'feature.'.$featureKey,
                'old_value_hash' => hash('sha256', $oldValue), 'new_value_hash' => hash('sha256', $newValue),
                'reason' => trim($data['reason']), 'created_at' => now(),
            ]);
        }, attempts: 3);
        return back()->with('status', 'Feature rollout setting saved and audited.');
    }
}
