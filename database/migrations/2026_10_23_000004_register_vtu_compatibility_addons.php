<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Preserve explicit registry mappings during the addon conversion window.
     * These manifests use Core's existing VTU controller and financial workflow;
     * they do not create a second wallet, order engine, or provider integration.
     */
    public function up(): void
    {
        $now = now();
        $addons = [
            [
                'addon_key' => 'service.vtu',
                'name' => 'VTU services',
                'description' => 'Shared VTU product catalogue, wallet order creation, bulk admission and recovery entry points.',
            ],
            [
                'addon_key' => 'service.airtime_data',
                'name' => 'Airtime and data compatibility',
                'description' => 'Compatibility manifest for existing airtime/data catalogue and purchase routes during service-specific addon conversion.',
            ],
            [
                'addon_key' => 'service.bills',
                'name' => 'Bills compatibility',
                'description' => 'Compatibility manifest for electricity and TV biller verification, catalogue and purchase routes.',
            ],
        ];

        foreach ($addons as $addon) {
            DB::table('platform_addons')->insertOrIgnore([
                'addon_key' => $addon['addon_key'],
                'name' => $addon['name'],
                'version' => '1.0.0',
                'minimum_core_version' => '1.0.0',
                'description' => $addon['description'],
                'dependencies' => json_encode([], JSON_THROW_ON_ERROR),
                'status' => 'enabled',
                'is_core_owned' => false,
                'compatibility_status' => 'compatible',
                'compatibility_message' => 'Compatibility manifest registered; service-specific and Core admission policies remain authoritative.',
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Do not remove compatibility manifests that may be referenced by live orders.
     */
    public function down(): void
    {
        // Forward-only in production.
    }
};
