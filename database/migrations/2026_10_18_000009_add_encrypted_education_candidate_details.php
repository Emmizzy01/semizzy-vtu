<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('education_orders') && ! Schema::hasColumn('education_orders', 'encrypted_candidate_details')) {
            Schema::table('education_orders', function (Blueprint $table): void {
                $table->longText('encrypted_candidate_details')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Preserve potentially sensitive education order data during rollback.
    }
};
