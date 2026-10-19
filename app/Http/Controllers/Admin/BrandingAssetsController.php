<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSettingAudit;
use App\Services\IntegrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BrandingAssetsController extends Controller
{
    public function update(Request $request, IntegrationSettings $settings): RedirectResponse
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'favicon' => ['nullable', 'image', 'mimes:png,webp', 'max:512'],
            'social_image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'banner' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        $directory = public_path('uploads/branding');
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ValidationException::withMessages(['logo' => 'Branding upload directory could not be created.']);
        }

        $fields = [
            'logo' => 'branding.logo',
            'favicon' => 'branding.favicon',
            'social_image' => 'branding.social_image',
            'banner' => 'branding.banner',
        ];
        $saved = [];
        foreach ($fields as $field => $key) {
            if (! $request->hasFile($field)) continue;
            $file = $request->file($field);
            if (! $file || ! $file->isValid()) {
                throw ValidationException::withMessages([$field => 'Please choose a valid image file.']);
            }
            $filename = $field.'-'.Str::uuid().'.'.strtolower($file->extension());
            $file->move($directory, $filename);
            $saved[$key] = '/uploads/branding/'.$filename;
        }
        if ($saved === []) {
            throw ValidationException::withMessages(['logo' => 'Choose at least one image to upload.']);
        }

        DB::transaction(function () use ($request, $settings, $saved): void {
            foreach ($saved as $key => $url) {
                $old = (string) $settings->get($key, '');
                $settings->set($key, $url, (int) $request->user()->id);
                PlatformSettingAudit::query()->create([
                    'actor_user_id' => $request->user()->id,
                    'setting_key' => $key,
                    'old_value_hash' => hash('sha256', $old),
                    'new_value_hash' => hash('sha256', $url),
                    'reason' => trim((string) $request->input('reason')),
                    'created_at' => now(),
                ]);
            }
        }, attempts: 3);

        return back()->with('status', 'Brand assets updated. Changes are recorded in the audit trail.');
    }
}
