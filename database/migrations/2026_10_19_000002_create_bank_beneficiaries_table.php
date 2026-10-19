<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_beneficiaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('bank_code', 20);
            $table->string('bank_name', 120);
            $table->text('account_number');
            $table->char('account_number_last4', 4);
            $table->string('account_name', 190);
            $table->string('provider_recipient_code', 100)->unique();
            $table->string('verification_status', 24)->default('verified');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'verification_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_beneficiaries');
    }
};
