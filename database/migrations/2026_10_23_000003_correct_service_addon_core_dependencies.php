<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Core identity and audit are platform capabilities, not addon manifests.
     * Keep these service addons independent in the addon dependency graph;
     * their runtime policies still enforce the Core feature and security gates.
     */
    public function up(): void
    {
        $now = now();

        DB::table('platform_addons')
            ->whereIn('addon_key', [
                'service.education',
                'service.streaming',
                'service.vtu-website-requests',
            ])
            ->update([
                'dependencies' => json_encode([], JSON_THROW_ON_ERROR),
                'compatibility_status' => 'compatible',
                'compatibility_message' => 'Core capabilities are enforced by runtime admission policies; addon dependencies are empty.',
                'updated_at' => $now,
            ]);
    }

    /**
     * Do not restore invalid dependency declarations or overwrite live addon state.
     */
    public function down(): void
    {
        // Forward-only in production.
    }
};
