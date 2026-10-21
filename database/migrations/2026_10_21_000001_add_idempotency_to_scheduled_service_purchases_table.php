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
        Schema::table('scheduled_service_purchases', function (Blueprint $table): void {
            $table->dropUnique('scheduled_purchases_user_idempotency_unique');
            $table->dropColumn(['idempotency_key', 'request_hash']);
        });
    }
};
