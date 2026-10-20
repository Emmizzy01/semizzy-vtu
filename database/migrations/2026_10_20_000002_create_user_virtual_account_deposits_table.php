<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_virtual_account_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_virtual_account_id')->constrained('user_virtual_accounts')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_transaction_id', 150);
            $table->string('provider_reference', 150);
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24)->default('received');
            $table->foreignId('wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->char('payload_hash', 64);
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_transaction_id'], 'virtual_account_provider_tx_unique');
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_virtual_account_deposits');
    }
};
