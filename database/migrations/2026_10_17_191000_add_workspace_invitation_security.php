<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_workspace_members', function (Blueprint $table): void {
            $table->string('invite_token_hash', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('business_workspace_members', function (Blueprint $table): void {
            $table->dropIndex(['invite_expires_at']);
            $table->dropUnique(['invite_token_hash']);
            $table->dropColumn(['invite_token_hash', 'invite_expires_at']);
        });
    }
};
