<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_payouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('bank_beneficiary_id')->constrained('bank_beneficiaries')->restrictOnDelete();
            $table->char('currency', 3)->default('NGN');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->string('status', 32)->default('queued');
            $table->string('idempotency_key', 128);
            $table->char('request_hash', 64);
            $table->string('provider_reference', 100)->unique();
            $table->string('provider_transfer_code', 120)->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key'], 'bank_payout_user_idem_unique');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_payouts');
    }
};
