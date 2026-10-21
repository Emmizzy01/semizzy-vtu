<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_service_purchases', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->unique(['user_id', 'idempotency_key'], 'scheduled_purchases_user_idempotency_unique');
        });
    }

    public function down(): void
    {
        // MariaDB may use the composite unique index to support the user_id
        // foreign key because user_id is its leading column. Add a standalone
        // user_id index first so dropping the composite index is safe.
        Schema::table('scheduled_service_purchases', function (Blueprint $table): void {
            $table->index('user_id', 'scheduled_purchases_user_id_index');
        });

        Schema::table('scheduled_service_purchases', function (Blueprint $table): void {
            $table->dropUnique('scheduled_purchases_user_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};
