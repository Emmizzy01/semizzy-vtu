<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Split airtime and data into independently controllable service features.
     * Defaults preserve the previously enabled aggregate airtime/data workflow;
     * existing operator-configured rows are never overwritten.
     */
    public function up(): void
    {
        $now = now();
        $dependencies = json_encode(
            ['core.identity', 'core.wallet_ledger', 'core.audit'],
            JSON_THROW_ON_ERROR,
        );

        foreach ([
            [
                'feature_key' => 'service.airtime',
                'label' => 'Airtime',
                'description' => 'Airtime catalogue and wallet-funded purchase workflow under the airtime addon.',
            ],
            [
                'feature_key' => 'service.data',
                'label' => 'Mobile data',
                'description' => 'Mobile data catalogue and wallet-funded purchase workflow under the data addon.',
            ],
        ] as $feature) {
            DB::table('platform_feature_controls')->insertOrIgnore([
                'feature_key' => $feature['feature_key'],
                'label' => $feature['label'],
                'category' => 'Everyday services',
                'owner' => 'VTU operations',
                'description' => $feature['description'],
                'dependencies' => $dependencies,
                'default_enabled' => true,
                'is_enabled' => true,
                'is_core' => false,
                'is_implemented' => true,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Preserve service controls and operator choices on production rollback.
     */
    public function down(): void
    {
        // Forward-only in production.
    }
};
