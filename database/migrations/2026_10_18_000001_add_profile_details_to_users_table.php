<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('avatar_path')->nullable();
            $table->string('address', 255)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('sex', 32)->nullable();
            $table->string('mobile_number', 20)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['mobile_number']);
            $table->dropColumn(['avatar_path', 'address', 'date_of_birth', 'sex', 'mobile_number']);
        });
    }
};
