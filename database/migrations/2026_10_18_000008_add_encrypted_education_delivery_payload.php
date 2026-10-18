<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('education_orders') && ! Schema::hasColumn('education_orders', 'provider_delivery_payload')) {
            Schema::table('education_orders', function (Blueprint $table): void {
                // Sensitive exam PINs/tokens are stored using Laravel's encrypted cast.
                // Never place provider delivery payloads in logs or safe_provider_response.
                $table->longText('provider_delivery_payload')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Keep encrypted education delivery records during rollback; no destructive drop.
    }
};
