<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vtu_website_requests')) {
            Schema::create('vtu_website_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 24)->unique();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('request_type', 32);
                $table->string('business_name', 120)->nullable();
                $table->string('contact_phone', 20);
                $table->unsignedBigInteger('budget_minor')->nullable();
                $table->text('description');
                $table->string('status', 32)->default('submitted');
                $table->text('admin_note')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('vtu_website_request_events')) {
            if (!Schema::hasTable('vtu_website_requests')) {
                throw new RuntimeException('Cannot create VTU website request events without vtu_website_requests.');
            }

            Schema::create('vtu_website_request_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')->constrained('vtu_website_requests')->restrictOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 40);
                $table->string('old_status', 32)->nullable();
                $table->string('new_status', 32)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['request_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vtu_website_request_events');
        Schema::dropIfExists('vtu_website_requests');
    }
};
