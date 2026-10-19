<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_service_purchases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('service_type', 50);
            $table->string('product_code', 100);
            $table->string('recipient', 40);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->json('service_details')->nullable();
            $table->string('frequency', 20);
            $table->timestamp('next_run_at')->index();
            $table->timestamp('last_run_at')->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->string('last_order_public_id', 40)->nullable();
            $table->string('last_result', 30)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_service_purchases');
    }
};
