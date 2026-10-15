<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconcile partial or incorrectly recorded deployments of the original
     * account-status migration without dropping or rewriting existing data.
     */
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            throw new RuntimeException('Cannot reconcile account status: users table does not exist.');
        }

        if (! Schema::hasColumn('users', 'account_status')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('account_status', 16)->default('active')->index();
            });
        }

        if (! Schema::hasColumn('users', 'account_status_reason')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('account_status_reason', 500)->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'account_status_changed_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('account_status_changed_at')->nullable();
            });
        }

        if (! Schema::hasTable('user_account_status_audits')) {
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
    }

    /**
     * This is a forward-only repair migration. Rolling it back must not remove
     * account status data or audit history from a production database.
     */
    public function down(): void
    {
        // Intentionally no-op: preserve production account status and audit data.
    }
};
