<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_beneficiary_claims', function (Blueprint $table): void {
            $table->id();
            // HMAC fingerprint only: never store plaintext account numbers here.
            $table->char('account_fingerprint', 64)->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('beneficiary_id')->nullable()->index();
            $table->string('status', 24)->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_beneficiary_claims');
    }
};
