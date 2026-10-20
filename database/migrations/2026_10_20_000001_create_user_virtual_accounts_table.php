<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_virtual_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_customer_reference', 150)->nullable();
            $table->string('provider_account_reference', 150)->nullable();
            $table->string('bank_code', 80)->nullable();
            $table->string('bank_name', 120);
            $table->string('account_name', 160);
            $table->string('account_number', 32);
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 24)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'provider_account_reference'], 'user_virtual_accounts_provider_ref_unique');
            $table->unique(['provider', 'account_number'], 'user_virtual_accounts_provider_number_unique');
            $table->index(['user_id', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_virtual_accounts');
    }
};
