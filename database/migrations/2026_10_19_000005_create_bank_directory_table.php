<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_directory', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('paystack');
            $table->string('bank_code', 20);
            $table->string('bank_name', 120);
            $table->boolean('enabled')->default(true);
            $table->timestamp('provider_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'bank_code']);
            $table->index(['enabled', 'bank_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_directory');
    }
};
