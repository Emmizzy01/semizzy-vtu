<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vtu_provider_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vtu_order_id')->constrained('vtu_orders')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('provider', 32);
            $table->string('outcome', 32)->default('started');
            $table->string('provider_reference', 191)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->boolean('definitive_failure')->default(false);
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['vtu_order_id', 'attempt_number'], 'vtu_provider_attempt_order_number_unique');
            $table->index(['vtu_order_id', 'provider', 'outcome'], 'vtu_provider_attempt_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vtu_provider_attempts');
    }
};
