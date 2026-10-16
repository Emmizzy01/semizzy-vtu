<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_deposits', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('transfer_reference', 120)->unique();
            $table->string('evidence_path', 500);
            $table->char('evidence_sha256', 64)->index();
            $table->boolean('duplicate_evidence')->default(false)->index();
            $table->string('status', 20)->default('pending');
            $table->timestamp('evidence_viewed_at')->nullable();
            $table->foreignId('evidence_viewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_reason', 500)->nullable();
            $table->foreignId('credited_wallet_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->restrictOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('manual_deposit_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('manual_deposit_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 50);
            $table->string('outcome', 20);
            $table->string('reason', 500)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['manual_deposit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_deposit_audits');
        Schema::dropIfExists('manual_deposits');
    }
};
