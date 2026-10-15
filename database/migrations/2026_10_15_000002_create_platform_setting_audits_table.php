<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_setting_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('setting_key', 150);
            $table->char('old_value_hash', 64);
            $table->char('new_value_hash', 64);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['setting_key', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('platform_setting_audits'); }
};
