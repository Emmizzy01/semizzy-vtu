<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_workspace_storefronts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_workspace_id')->unique()->constrained('business_workspaces')->cascadeOnDelete();
            $table->string('slug', 80)->unique();
            $table->string('display_name', 120);
            $table->string('tagline', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft')->index();
            $table->json('enabled_services')->nullable();
            $table->string('accent_color', 7)->default('#087443');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_workspace_storefronts');
    }
};
