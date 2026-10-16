<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 190);
            $table->char('payload_hash', 64);
            $table->string('event_type', 100)->nullable();
            $table->string('outcome', 24)->default('received');
            $table->foreignId('payment_intent_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('safe_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'payment_webhook_provider_event_unique');
            $table->index(['outcome', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};