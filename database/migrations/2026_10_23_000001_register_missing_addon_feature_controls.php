<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Register feature-control records required by addon-owned admission
     * policies. insertOrIgnore deliberately preserves any existing operator
     * configuration and keeps these features disabled until reviewed.
     */
    public function up(): void
    {
        $now = now();

        DB::table('platform_feature_controls')->insertOrIgnore([
            [
                'feature_key' => 'service.streaming_subscriptions',
                'label' => 'Streaming subscriptions',
                'category' => 'Everyday services',
                'owner' => 'Streaming operations',
                'description' => 'Streaming catalogue and provider-backed subscription workflows; purchases remain gated until fulfilment and recovery are verified.',
                'dependencies' => json_encode(['core.identity', 'core.wallet_ledger', 'core.audit'], JSON_THROW_ON_ERROR),
                'default_enabled' => false,
                'is_enabled' => false,
                'is_core' => false,
                'is_implemented' => false,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'feature_key' => 'service.vtu_website_requests',
                'label' => 'VTU website service requests',
                'category' => 'Business tools',
                'owner' => 'Website services',
                'description' => 'Customer requests for VTU website services with addon-owned admission and Core-owned persistence and review.',
                'dependencies' => json_encode(['core.identity', 'core.audit'], JSON_THROW_ON_ERROR),
                'default_enabled' => false,
                'is_enabled' => false,
                'is_core' => false,
                'is_implemented' => false,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * Intentionally non-destructive: feature controls may have been configured
     * by an operator after this migration and must not be deleted on rollback.
     */
    public function down(): void
    {
        // Forward-only in production; preserve operator configuration.
    }
};
