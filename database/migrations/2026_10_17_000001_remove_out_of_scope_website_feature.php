<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Website builder, website domains and hosting were explicitly removed
        // from the Semizzy One scope; remove the stale disabled roadmap toggle.
        DB::table('platform_feature_controls')->where('feature_key', 'service.websites')->delete();
    }

    public function down(): void
    {
        if (DB::table('platform_feature_controls')->where('feature_key', 'service.websites')->exists()) {
            return;
        }

        $now = now();
        DB::table('platform_feature_controls')->insert([
            'feature_key' => 'service.websites',
            'label' => 'Websites & domains',
            'category' => 'Business tools',
            'owner' => 'Platform operations',
            'description' => 'Website builder, reseller storefronts and domain workflows.',
            'dependencies' => json_encode(['core.identity', 'core.audit'], JSON_THROW_ON_ERROR),
            'default_enabled' => false,
            'is_enabled' => false,
            'is_core' => false,
            'is_implemented' => false,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
