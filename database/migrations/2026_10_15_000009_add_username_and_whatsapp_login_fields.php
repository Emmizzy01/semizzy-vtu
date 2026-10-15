<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username')->nullable()->unique();
            $table->string('whatsapp_number', 32)->nullable()->unique();
            $table->timestamp('whatsapp_verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropUnique(['whatsapp_number']);
            $table->dropColumn(['username', 'whatsapp_number', 'whatsapp_verified_at']);
        });
    }
};
