<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('platform_feature_controls')
            ->where('feature_key', 'service.vtu_website_requests')
            ->update(['is_implemented' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('platform_feature_controls')
            ->where('feature_key', 'service.vtu_website_requests')
            ->update(['is_implemented' => false, 'is_enabled' => false, 'updated_at' => now()]);
    }
};
