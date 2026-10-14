<?php

namespace App\Services;

use App\Models\IntegrationSetting;

class IntegrationSettings
{
    public function get(string $key, mixed $fallback = null): mixed
    {
        $setting = IntegrationSetting::query()->where('key', $key)->first();

        return $setting ? $setting->value : $fallback;
    }

    public function set(string $key, ?string $value, ?int $actorId): void
    {
        IntegrationSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by_user_id' => $actorId],
        );
    }

    public function has(string $key, mixed $fallback = null): bool
    {
        return trim((string) $this->get($key, $fallback)) !== '';
    }
}
