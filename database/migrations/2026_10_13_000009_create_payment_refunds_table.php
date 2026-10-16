<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('payment_intent_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('wallet_debit_transaction_id')->nullable()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->unsignedBigInteger('amount_minor');
            $table->string('reason', 500);
            $table->string('status', 40)->default('requested');
            $table->string('provider_refund_reference', 150)->nullable()->unique();
            $table->string('provider_status', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['requested_by_user_id', 'idempotency_key'], 'payment_refunds_request_idempotency_unique');
            $table->index(['payment_intent_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
