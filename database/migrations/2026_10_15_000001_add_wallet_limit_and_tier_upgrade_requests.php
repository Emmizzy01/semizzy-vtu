<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  if (Schema::hasTable('user_level_policies') && !Schema::hasColumn('user_level_policies', 'wallet_balance_limit_minor')) { Schema::table('user_level_policies', function (Blueprint $table): void { $table->unsignedBigInteger('wallet_balance_limit_minor')->nullable()->after('label'); }); }
  Schema::create('user_level_upgrade_requests', function(Blueprint $table): void {
   $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('current_level',20); $table->string('requested_level',20); $table->string('status',20)->default('pending'); $table->json('eligibility_snapshot')->nullable(); $table->text('user_note')->nullable(); $table->text('review_note')->nullable(); $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete(); $table->timestamp('reviewed_at')->nullable(); $table->timestamps(); $table->index(['user_id','status']); $table->index(['status','created_at']);
  });
 }
 public function down(): void { Schema::dropIfExists('user_level_upgrade_requests'); Schema::table('user_level_policies', function(Blueprint $table): void { if (Schema::hasColumn('user_level_policies','wallet_balance_limit_minor')) $table->dropColumn('wallet_balance_limit_minor'); }); }
};