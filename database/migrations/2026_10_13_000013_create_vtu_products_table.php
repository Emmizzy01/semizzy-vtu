<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vtu_products', function (Blueprint $table): void {
            $table->id();
            $table->string('service_type', 24);
            $table->string('network', 40);
            $table->string('code', 80)->unique();
            $table->string('name', 160);
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('face_value_minor')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['service_type', 'network', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vtu_products');
    }
};
