<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vtu_bulk_purchase_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('batch_key', 128);
            $table->char('request_hash', 64);
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedSmallInteger('item_count');
            $table->unsignedBigInteger('total_amount_minor')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'batch_key'], 'vtu_bulk_batch_user_key_unique');
            $table->index(['status', 'updated_at']);
        });

        Schema::create('vtu_bulk_purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('vtu_bulk_purchase_batches')->cascadeOnDelete();
            $table->unsignedSmallInteger('item_index');
            $table->string('idempotency_key', 128)->unique();
            $table->string('product_code', 100);
            $table->string('service_type', 40);
            $table->string('recipient', 40);
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->json('service_details')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->string('order_public_id', 40)->nullable()->index();
            $table->text('result_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'item_index'], 'vtu_bulk_item_position_unique');
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vtu_bulk_purchase_items');
        Schema::dropIfExists('vtu_bulk_purchase_batches');
    }
};
