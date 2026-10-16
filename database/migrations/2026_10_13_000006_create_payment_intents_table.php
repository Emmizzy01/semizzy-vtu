<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('reference', 100)->unique();
            $table->string('provider_transaction_id', 150)->nullable()->unique();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 24)->default('pending');
            $table->string('checkout_url', 2048)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['provider', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};