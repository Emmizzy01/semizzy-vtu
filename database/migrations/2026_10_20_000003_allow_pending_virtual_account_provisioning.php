<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_virtual_accounts', function (Blueprint $table): void {
            $table->string('bank_code', 80)->nullable()->change();
            $table->string('bank_name', 120)->nullable()->change();
            $table->string('account_name', 160)->nullable()->change();
            $table->string('account_number', 32)->nullable()->change();
            $table->timestamp('consented_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('user_virtual_accounts', function (Blueprint $table): void {
            $table->dropColumn('consented_at');
            // Pending provisioning rows must be resolved before rolling back this migration.
            $table->string('bank_code', 80)->nullable(false)->change();
            $table->string('bank_name', 120)->nullable(false)->change();
            $table->string('account_name', 160)->nullable(false)->change();
            $table->string('account_number', 32)->nullable(false)->change();
        });
    }
};
