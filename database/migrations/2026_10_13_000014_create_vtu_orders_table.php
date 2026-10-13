<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vtu_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('vtu_product_id')->constrained('vtu_products')->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('service_type', 24);
            $table->string('network', 40);
            $table->string('product_code', 80);
            $table->string('recipient', 32);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 32)->default('pending');
            $table->string('provider', 40);
            $table->string('provider_reference', 150)->nullable();
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('dispatch_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('safe_provider_response')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'vtu_order_user_idempotency_unique');
            $table->index(['status', 'created_at']);
            $table->index(['provider', 'provider_reference']);
            $table->index(['recipient', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vtu_orders');
    }
};
