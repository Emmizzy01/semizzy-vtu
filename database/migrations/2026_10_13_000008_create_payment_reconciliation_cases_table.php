<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reconciliation_cases', function (Blueprint $table): void {
            $table->id();
            $table->char('case_key', 64)->unique();
            $table->string('provider', 40);
            $table->string('reference', 100);
            $table->foreignId('payment_intent_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('case_type', 40);
            $table->string('provider_status', 40)->nullable();
            $table->string('provider_transaction_id', 150)->nullable();
            $table->unsignedBigInteger('expected_amount_minor')->nullable();
            $table->unsignedBigInteger('observed_amount_minor')->nullable();
            $table->char('expected_currency', 3)->nullable();
            $table->char('observed_currency', 3)->nullable();
            $table->json('details')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'case_type', 'created_at']);
            $table->index(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliation_cases');
    }
};