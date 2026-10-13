<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->index(['wallet_id', 'status', 'created_at'], 'wallet_tx_history_status_idx');
        });

        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->index(['wallet_id', 'entry_type', 'id'], 'wallet_ledger_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('wallet_ledger_type_idx');
        });

        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex('wallet_tx_history_status_idx');
        });
    }
};
