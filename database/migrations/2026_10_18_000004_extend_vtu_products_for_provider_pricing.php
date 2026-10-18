<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vtu_products', function (Blueprint $table): void {
            $table->string('provider_key', 32)->nullable()->after('service_type')->index();
            $table->string('provider_product_code', 120)->nullable()->after('code');
            $table->unsignedBigInteger('provider_cost_minor')->nullable()->after('price_minor');
            $table->string('data_type', 40)->nullable()->after('network');
            $table->string('validity', 80)->nullable()->after('data_type');
            $table->json('pricing_by_user_category')->nullable()->after('metadata');
            $table->json('commission_by_user_category')->nullable()->after('pricing_by_user_category');
            $table->index(['provider_key', 'service_type', 'network']);
        });
    }

    public function down(): void
    {
        Schema::table('vtu_products', function (Blueprint $table): void {
            $table->dropIndex(['provider_key', 'service_type', 'network']);
            $table->dropColumn([
                'provider_key',
                'provider_product_code',
                'provider_cost_minor',
                'data_type',
                'validity',
                'pricing_by_user_category',
                'commission_by_user_category',
            ]);
        });
    }
};
