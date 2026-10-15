<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('transaction_pin_hash')->nullable();
            $table->unsignedTinyInteger('transaction_pin_failed_attempts')->default(0);
            $table->timestamp('transaction_pin_locked_until')->nullable();
            $table->timestamp('transaction_pin_changed_at')->nullable();
        });
        Schema::create('transaction_security_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 60);
            $table->string('outcome', 24);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('transaction_security_events');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['transaction_pin_hash', 'transaction_pin_failed_attempts', 'transaction_pin_locked_until', 'transaction_pin_changed_at']);
        });
    }
};
