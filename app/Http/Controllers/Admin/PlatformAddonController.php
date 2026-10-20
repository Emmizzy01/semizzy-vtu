<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformAddon;
use App\Models\PlatformSettingAudit;
use App\Services\Addons\PlatformAddonRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAddonController extends Controller
{
    public function index(PlatformAddonRegistry $registry): Response
    {
        $registry->refreshCompatibility();

        return Inertia::render('Admin/PlatformAddons/Index', [
            'coreVersion' => $registry->coreVersion(),
            'addons' => PlatformAddon::query()->orderBy('name')->get()->map(fn (PlatformAddon $addon): array => [
                'id' => $addon->id,
                'key' => $addon->addon_key,
                'name' => $addon->name,
                'version' => $addon->version,
                'minimumCoreVersion' => $addon->minimum_core_version,
                'description' => $addon->description,
                'dependencies' => $addon->dependencies ?? [],
                'status' => $addon->status,
                'isCoreOwned' => $addon->is_core_owned,
                'compatibilityStatus' => $addon->compatibility_status,
                'compatibilityMessage' => $addon->compatibility_message,
            ])->values(),
        ]);
    }

    public function store(Request $request, PlatformAddonRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'addon_key' => ['required', 'string', 'max:100', 'regex:/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'minimum_core_version' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'dependencies' => ['sometimes', 'array', 'max:30'],
            'dependencies.*' => ['required', 'string', 'max:100', 'distinct', 'regex:/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/'],
        ]);

        $existing = PlatformAddon::query()->where('addon_key', strtolower($data['addon_key']))->first();
        if ($existing?->status === 'enabled') {
            return back()->withErrors(['addon_key' => 'Disable this add-on before changing its manifest or dependency list.']);
        }

        $addon = $registry->register($data, (int) $request->user()->id);
        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id,
            'setting_key' => 'addon.registry.'.$addon->addon_key,
            'old_value_hash' => hash('sha256', 'registered-manifest'),
            'new_value_hash' => hash('sha256', json_encode([
                $addon->version, $addon->minimum_core_version, $addon->dependencies,
            ], JSON_THROW_ON_ERROR)),
            'reason' => 'Add-on manifest registered or updated through the admin registry.',
        ]);

        return back()->with('success', 'Add-on manifest saved. Compatibility must be satisfied before it can be enabled.');
    }

    public function toggle(Request $request, PlatformAddon $addon, PlatformAddonRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $oldStatus = $addon->status;
        $updated = $registry->setEnabled($addon, (bool) $data['enabled'], (int) $request->user()->id);
        PlatformSettingAudit::query()->create([
            'actor_user_id' => $request->user()->id,
            'setting_key' => 'addon.status.'.$updated->addon_key,
            'old_value_hash' => hash('sha256', $oldStatus),
            'new_value_hash' => hash('sha256', $updated->status),
            'reason' => trim($data['reason']),
        ]);

        return back()->with('success', $updated->status === 'enabled' ? 'Add-on marked enabled in the registry.' : 'Add-on disabled in the registry.');
    }
}
