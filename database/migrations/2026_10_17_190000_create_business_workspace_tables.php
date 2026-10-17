<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('type', 24)->default('business')->index();
            $table->string('status', 24)->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->index(['owner_user_id', 'status']);
        });

        Schema::create('business_workspace_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_workspace_id')->constrained('business_workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 24)->default('agent');
            $table->json('permissions')->nullable();
            $table->string('status', 24)->default('invited');
            $table->foreignId('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['business_workspace_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_workspace_members');
        Schema::dropIfExists('business_workspaces');
    }
};
