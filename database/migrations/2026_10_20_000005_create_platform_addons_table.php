<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_addons', function (Blueprint $table): void {
            $table->id();
            $table->string('addon_key', 100)->unique();
            $table->string('name', 160);
            $table->string('version', 40);
            $table->string('minimum_core_version', 40)->default('1.0.0');
            $table->text('description')->nullable();
            $table->json('dependencies')->nullable();
            $table->string('status', 20)->default('disabled')->index();
            $table->boolean('is_core_owned')->default(false);
            $table->string('compatibility_status', 24)->default('unchecked')->index();
            $table->text('compatibility_message')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_addons');
    }
};
