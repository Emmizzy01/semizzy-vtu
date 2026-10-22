<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $addons = [
            ['addon_key' => 'service.airtime', 'name' => 'Airtime', 'description' => 'Airtime purchase and recovery entry points.'],
            ['addon_key' => 'service.data', 'name' => 'Data', 'description' => 'Mobile data catalogue, purchase and recovery entry points.'],
            ['addon_key' => 'service.electricity', 'name' => 'Electricity', 'description' => 'Electricity bill verification and purchase entry points.'],
            ['addon_key' => 'service.tv', 'name' => 'TV Subscription', 'description' => 'TV subscription verification and purchase entry points.'],
        ];

        foreach ($addons as $addon) {
            DB::table('platform_addons')->insertOrIgnore([
                'addon_key' => $addon['addon_key'],
                'name' => $addon['name'],
                'version' => '1.0.0',
                'minimum_core_version' => '1.0.0',
                'description' => $addon['description'],
                'dependencies' => json_encode([]),
                'status' => 'enabled',
                'is_core_owned' => false,
                'compatibility_status' => 'compatible',
                'compatibility_message' => 'Registered service implementation; Core feature controls remain authoritative.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Preserve production add-on state and audit history on rollback.
    }
};
