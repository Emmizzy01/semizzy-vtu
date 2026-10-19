<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 50);
            $table->string('label', 80);
            $table->string('recipient', 40);
            $table->string('network', 50)->nullable();
            $table->string('product_code', 100)->nullable();
            $table->json('service_details')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'service_type', 'label']);
            $table->unique(['user_id', 'service_type', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_beneficiaries');
    }
};
