<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('NGN');
            $table->string('status', 20)->default('active');
            // Amounts are stored in minor units (kobo for NGN), never floating point.
            $table->bigInteger('balance_minor')->default(0);
            $table->bigInteger('available_balance_minor')->default(0);
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'currency']);
            $table->index(['status', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
