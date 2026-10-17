<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_level_policies', function (Blueprint $table): void {
            $table->id();
            $table->string('level', 16)->unique();
            $table->string('label', 80);
            $table->unsignedBigInteger('wallet_balance_limit_minor')->nullable();
            $table->unsignedBigInteger('max_single_transaction_minor')->nullable();
            $table->unsignedBigInteger('daily_transaction_limit_minor')->nullable();
            $table->boolean('requires_verified_kyc')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        $now = now();
        DB::table('user_level_policies')->insert(array_map(fn (int $level): array => [
            'level' => 'level_'.$level,
            'label' => $level === 4 ? 'Merchant' : 'Level '.$level,
            'max_single_transaction_minor' => null,
            'daily_transaction_limit_minor' => null,
            'requires_verified_kyc' => false,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], [1, 2, 3, 4]));
        Schema::create('user_level_policy_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('level', 16);
            $table->char('old_values_hash', 64);
            $table->char('new_values_hash', 64);
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['level', 'created_at']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('user_level_policy_audits');
        Schema::dropIfExists('user_level_policies');
    }
};
