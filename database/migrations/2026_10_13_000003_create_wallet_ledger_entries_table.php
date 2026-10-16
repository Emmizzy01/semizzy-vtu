<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_transaction_id')->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('entry_type', 20);
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->bigInteger('balance_after_minor');
            $table->string('memo', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique('wallet_transaction_id');
            $table->index(['wallet_id', 'id']);
            $table->index(['wallet_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger_entries');
    }
};
