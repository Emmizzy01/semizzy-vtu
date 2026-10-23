<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register addon manifests for service workflows already present in the
     * application. Registration does not enable customer purchases: feature
     * controls and provider/recovery admission remain authoritative.
     */
    public function up(): void
    {
        $now = now();

        $addons = [
            [
                'addon_key' => 'service.education',
                'name' => 'Education and exam services',
                'description' => 'Education catalogue, authorized exam services and protected digital delivery workflows.',
            ],
            [
                'addon_key' => 'service.streaming',
                'name' => 'Streaming subscriptions',
                'description' => 'Streaming plan catalogue; provider-backed purchases remain gated pending verified fulfilment and recovery.',
            ],
            [
                'addon_key' => 'service.vtu-website-requests',
                'name' => 'VTU website service requests',
                'description' => 'Customer website-service requests with audited submission and administrative review.',
            ],
        ];

        foreach ($addons as $addon) {
            DB::table('platform_addons')->insertOrIgnore([
                'addon_key' => $addon['addon_key'],
                'name' => $addon['name'],
                'version' => '1.0.0',
                'minimum_core_version' => '1.0.0',
                'description' => $addon['description'],
                'dependencies' => json_encode(['core.identity', 'core.audit'], JSON_THROW_ON_ERROR),
                'status' => 'enabled',
                'is_core_owned' => false,
                'compatibility_status' => 'compatible',
                'compatibility_message' => 'Manifest registered; feature and operation admission remain authoritative.',
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The request workflow is implemented, but must remain disabled until
        // an operator explicitly enables its feature control. This update also
        // repairs installations where the older feature row was absent when
        // the historical "mark implemented" migration ran.
        DB::table('platform_feature_controls')
            ->where('feature_key', 'service.vtu_website_requests')
            ->update([
                'is_implemented' => true,
                'updated_at' => $now,
            ]);
    }

    /**
     * Preserve production addon state and operator-managed feature controls.
     */
    public function down(): void
    {
        // Forward-only in production.
    }
};
