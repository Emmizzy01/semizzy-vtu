<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('account_status', 16)->default('active')->index();
            $table->string('account_status_reason', 500)->nullable();
            $table->timestamp('account_status_changed_at')->nullable();
        });
        Schema::create('user_account_status_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('old_status', 16);
            $table->string('new_status', 16);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['target_user_id', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_account_status_audits');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_status']);
            $table->dropColumn(['account_status', 'account_status_reason', 'account_status_changed_at']);
        });
    }
};
