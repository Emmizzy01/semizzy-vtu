<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->timestamp('status_changed_at')->nullable()->after('status');
            $table->index(['status', 'status_changed_at'], 'payment_refunds_status_changed_index');
        });

        DB::table('payment_refunds')->update([
            'status_changed_at' => DB::raw('COALESCE(updated_at, created_at)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropIndex('payment_refunds_status_changed_index');
            $table->dropColumn('status_changed_at');
        });
    }
};
