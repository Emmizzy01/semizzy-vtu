<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('request_type', 24)->index();
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('submitted')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('data_retention_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('record_category', 80)->unique();
            $table->unsignedSmallInteger('retention_days');
            $table->boolean('enabled')->default(false);
            $table->boolean('requires_legal_review')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('security_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 32)->unique();
            $table->string('title', 180);
            $table->text('description');
            $table->string('severity', 16)->index();
            $table->string('status', 24)->default('open')->index();
            $table->string('category', 60)->index();
            $table->foreignId('reported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();
            $table->timestamp('contained_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('security_incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained('security_incidents')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->text('note');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['incident_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incident_events');
        Schema::dropIfExists('security_incidents');
        Schema::dropIfExists('data_retention_policies');
        Schema::dropIfExists('privacy_requests');
    }
};
