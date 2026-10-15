<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('user_type', 32)->default('customer')->index();
            $table->string('user_level', 16)->default('level_1')->index();
            $table->string('kyc_status', 24)->default('unverified')->index();
        });
        Schema::create('user_role_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_user_type', 32);
            $table->string('new_user_type', 32);
            $table->string('old_user_level', 16);
            $table->string('new_user_level', 16);
            $table->string('old_kyc_status', 24);
            $table->string('new_kyc_status', 24);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_user_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('user_role_audits');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['user_type']);
            $table->dropIndex(['user_level']);
            $table->dropIndex(['kyc_status']);
            $table->dropColumn(['user_type', 'user_level', 'kyc_status']);
        });
    }
};
