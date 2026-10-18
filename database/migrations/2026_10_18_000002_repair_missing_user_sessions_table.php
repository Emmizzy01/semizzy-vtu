<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs production schema drift where the original user_sessions migration
 * was recorded as applied but the table is absent.
 *
 * The original migration runs first on a fresh/pending install. This migration
 * is intentionally guarded so it is a no-op when that table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_sessions')) {
            return;
        }

        Schema::create('user_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('session_hash', 64)->unique();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('last_activity')->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        // Do not drop user_sessions here: it may have been created by the
        // original migration or contain active security/session records.
    }
};
