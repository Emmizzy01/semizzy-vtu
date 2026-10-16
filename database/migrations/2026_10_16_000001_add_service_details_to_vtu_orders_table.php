<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vtu_orders', function (Blueprint $table): void {
            $table->json('service_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('vtu_orders', function (Blueprint $table): void {
            $table->dropColumn('service_details');
        });
    }
};
