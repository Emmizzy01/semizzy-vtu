<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FEATURES = [
        [
            'feature_key' => 'service.streaming_subscriptions',
            'label' => 'Streaming subscriptions',
            'category' => 'Everyday services',
            'owner' => 'Subscription operations',
            'description' => 'Provider-backed streaming and digital subscription fulfilment.',
        ],
        [
            'feature_key' => 'service.betting',
            'label' => 'Betting platforms',
            'category' => 'Everyday services',
            'owner' => 'Provider operations',
            'description' => 'Betting-platform wallet funding through verified provider-specific workflows.',
        ],
        [
            'feature_key' => 'service.social_accounts',
            'label' => 'Social media accounts',
            'category' => 'Business tools',
            'owner' => 'Marketplace operations',
            'description' => 'Moderated social media account listings and delivery workflows.',
        ],
        [
            'feature_key' => 'service.number_verification',
            'label' => 'Number verification',
            'category' => 'Verification services',
            'owner' => 'Verification operations',
            'description' => 'Provider-backed number verification with consent, privacy and abuse controls.',
        ],
        [
            'feature_key' => 'service.vtu_website_requests',
            'label' => 'VTU website requests',
            'category' => 'Business tools',
            'owner' => 'Platform operations',
            'description' => 'Customer requests for VTU website services; distinct from the removed website builder and domain-hosting feature.',
        ],
    ];

    public function up(): void
    {
        $now = now();
        $rows = array_map(static fn (array $feature): array => [
            ...$feature,
            'dependencies' => json_encode(['core.identity', 'core.audit'], JSON_THROW_ON_ERROR),
            'default_enabled' => false,
            'is_enabled' => false,
            'is_core' => false,
            'is_implemented' => false,
            'updated_by_user_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], self::FEATURES);

        DB::table('platform_feature_controls')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('platform_feature_controls')
            ->whereIn('feature_key', array_column(self::FEATURES, 'feature_key'))
            ->delete();
    }
};
