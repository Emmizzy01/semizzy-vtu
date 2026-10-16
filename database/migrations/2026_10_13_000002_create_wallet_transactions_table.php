<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('type', 32);
            $table->string('status', 20)->default('pending');
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('balance_before_minor');
            $table->unsignedBigInteger('balance_after_minor');
            $table->string('reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['wallet_id', 'idempotency_key'], 'wallet_tx_idempotency_unique');
            $table->index(['wallet_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
