<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs production schema drift for account tiers, KYC and business storefronts.
 *
 * This migration is deliberately additive and guarded: it creates only missing
 * tables/columns and never drops or rewrites existing records.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_level_policies')
            && !Schema::hasColumn('user_level_policies', 'wallet_balance_limit_minor')) {
            Schema::table('user_level_policies', function (Blueprint $table): void {
                $table->unsignedBigInteger('wallet_balance_limit_minor')->nullable()->after('label');
            });
        }

        if (!Schema::hasTable('user_level_upgrade_requests')) {
            Schema::create('user_level_upgrade_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('current_level', 20);
                $table->string('requested_level', 20);
                $table->string('status', 20)->default('pending');
                $table->json('eligibility_snapshot')->nullable();
                $table->text('user_note')->nullable();
                $table->text('review_note')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index(['status', 'created_at']);
            });
        }

        if (!Schema::hasTable('kyc_submissions')) {
            Schema::create('kyc_submissions', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('document_type', 32);
                $table->string('evidence_path', 255);
                $table->char('evidence_sha256', 64);
                $table->string('status', 24)->default('pending');
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('evidence_viewed_at')->nullable();
                $table->foreignId('evidence_viewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->string('review_reason', 500)->nullable();
                $table->timestamps();
                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'status']);
            });
        }

        if (!Schema::hasTable('business_workspace_storefronts')) {
            if (!Schema::hasTable('business_workspaces')) {
                throw new RuntimeException(
                    'Cannot repair business_workspace_storefronts because business_workspaces is missing. Reconcile the business workspace migrations first.'
                );
            }

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
    }

    public function down(): void
    {
        // Intentionally non-destructive: production recovery must not drop
        // account-tier, KYC, or storefront data.
    }
};
