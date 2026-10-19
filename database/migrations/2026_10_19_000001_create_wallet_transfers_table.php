<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transfers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('status', 24)->default('posted');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->foreignId('sender_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->foreignId('recipient_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->unique(['sender_user_id', 'idempotency_key'], 'wallet_transfer_sender_idem_unique');
            $table->index(['sender_user_id', 'created_at']);
            $table->index(['recipient_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transfers');
    }
};
