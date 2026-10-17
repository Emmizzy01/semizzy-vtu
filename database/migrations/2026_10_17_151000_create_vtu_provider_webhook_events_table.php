<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vtu_provider_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 191);
            $table->string('event_type', 100);
            $table->string('provider_reference', 191)->nullable()->index();
            $table->json('payload');
            $table->char('payload_sha256', 64);
            $table->timestamp('received_at');
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_id'], 'vtu_webhook_provider_event_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vtu_provider_webhook_events');
    }
};
