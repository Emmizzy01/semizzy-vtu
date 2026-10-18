<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('education_service_products')) {
            Schema::create('education_service_products', function (Blueprint $table): void {
                $table->id();
                $table->string('service_type', 40);
                $table->string('exam_body', 60);
                $table->string('name', 160);
                $table->string('code', 100)->unique();
                $table->string('provider', 60)->nullable();
                $table->string('provider_product_code', 120)->nullable();
                $table->unsignedBigInteger('provider_cost_minor')->nullable();
                $table->unsignedBigInteger('price_minor');
                $table->string('currency', 3)->default('NGN');
                $table->json('metadata')->nullable();
                $table->boolean('requires_candidate_validation')->default(true);
                $table->boolean('is_active')->default(false);
                $table->boolean('purchase_approved')->default(false);
                $table->timestamps();
                $table->index(['service_type', 'exam_body', 'is_active'], 'edu_products_type_body_active_idx');
                $table->index(['provider', 'provider_product_code'], 'edu_products_provider_code_idx');
            });
        }

        if (Schema::hasTable('platform_feature_controls')) {
            DB::table('platform_feature_controls')->insertOrIgnore([
                'feature_key' => 'service.education',
                'label' => 'Education services',
                'category' => 'Education & authorized services',
                'owner' => 'Education operations',
                'description' => 'Provider-backed examination pins and education services with candidate validation and transaction safeguards.',
                'dependencies' => json_encode(['core.identity', 'core.wallet', 'core.audit'], JSON_THROW_ON_ERROR),
                'default_enabled' => false,
                'is_enabled' => false,
                'is_core' => false,
                'is_implemented' => false,
                'updated_by_user_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally retain catalogue and feature-control records during rollback.
        // Production data must not be removed automatically.
    }
};
