<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('streaming_subscription_products')) {
            return;
        }

        Schema::create('streaming_subscription_products', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 60);
            $table->string('name', 160);
            $table->string('code', 100)->unique();
            $table->string('provider', 60)->nullable();
            $table->string('provider_product_code', 120)->nullable();
            $table->string('duration', 80)->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3)->default('NGN');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('purchase_approved')->default(false);
            $table->timestamps();
            $table->index(['platform', 'is_active']);
            $table->index(['provider', 'provider_product_code'], 'stream_sub_provider_code_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streaming_subscription_products');
    }
};
