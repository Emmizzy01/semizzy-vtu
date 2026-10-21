<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('mobile_verified_at')->nullable();
        });

        Schema::create('verified_mobile_numbers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->unique();
            $table->string('mobile_number', 16)->unique();
            $table->timestamps();
        });

        Schema::create('mobile_verification_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('mobile_number', 16);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mobile_verification_challenges');
        Schema::dropIfExists('verified_mobile_numbers');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('mobile_verified_at');
        });
    }
};
