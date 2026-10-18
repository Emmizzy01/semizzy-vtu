<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_orders')) {
            Schema::create('education_orders', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
                $table->foreignId('education_service_product_id')->constrained('education_service_products')->restrictOnDelete();
                // Keep the ledger transaction ID as a durable reference without a cross-table FK.\n                // This avoids migration teardown ordering coupling in MariaDB tests.\n                $table->unsignedBigInteger('wallet_transaction_id')->nullable();
                $table->string('idempotency_key', 128);
                $table->char('request_hash', 64);
                $table->string('provider', 60);
                $table->string('provider_reference', 150)->nullable();
                $table->string('status', 32)->default('pending');
                $table->unsignedBigInteger('amount_minor');
                $table->string('currency', 3)->default('NGN');
                $table->json('candidate_details');
                $table->json('safe_provider_response')->nullable();
                $table->string('failure_code', 80)->nullable();
                $table->timestamp('dispatch_started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'idempotency_key'], 'education_orders_user_idempotency_unique');
                $table->index(['status', 'updated_at'], 'education_orders_status_updated_idx');
                $table->index(['provider', 'provider_reference'], 'education_orders_provider_reference_idx');
            });
        }

        if (! Schema::hasTable('education_provider_attempts')) {
            Schema::create('education_provider_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('education_order_id')->constrained('education_orders')->restrictOnDelete();
                $table->unsignedSmallInteger('attempt_number');
                $table->string('provider', 60);
                $table->string('outcome', 32)->default('started');
                $table->char('request_fingerprint', 64);
                $table->string('provider_reference', 150)->nullable();
                $table->string('message_code', 80)->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['education_order_id', 'attempt_number'], 'education_attempts_order_number_unique');
                $table->index(['outcome', 'updated_at'], 'education_attempts_outcome_updated_idx');
            });
        }
    }

    public function down(): void
    {
        // Production transaction history is retained; this migration is intentionally non-destructive.
    }
};
