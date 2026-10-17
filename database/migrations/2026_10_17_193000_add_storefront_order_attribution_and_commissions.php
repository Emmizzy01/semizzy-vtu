<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vtu_orders', function (Blueprint $table): void {
            $table->foreignId('business_workspace_storefront_id')
                ->nullable()
                ->after('vtu_product_id')
                ->constrained('business_workspace_storefronts', 'id', 'vtu_orders_storefront_fk')
                ->restrictOnDelete();
            $table->index(['business_workspace_storefront_id', 'status', 'created_at'], 'vtu_orders_storefront_status_created_index');
        });

        Schema::table('business_workspace_storefronts', function (Blueprint $table): void {
            // Rates are platform-controlled. Zero is intentionally the safe default
            // until a commission policy is explicitly configured.
            $table->unsignedSmallInteger('commission_rate_basis_points')->default(0);
        });

        Schema::create('business_workspace_commissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('business_workspace_storefront_id')
                ->constrained('business_workspace_storefronts', 'id', 'workspace_commissions_storefront_fk')
                ->restrictOnDelete();
            $table->foreignId('vtu_order_id')
                ->unique()
                ->constrained('vtu_orders', 'id', 'workspace_commissions_order_fk')
                ->restrictOnDelete();
            $table->foreignId('beneficiary_user_id')
                ->constrained('users', 'id', 'workspace_commissions_beneficiary_fk')
                ->restrictOnDelete();
            $table->unsignedBigInteger('gross_amount_minor');
            $table->unsignedSmallInteger('rate_basis_points');
            $table->unsignedBigInteger('commission_amount_minor');
            $table->string('status', 24)->default('accrued')->index();
            $table->foreignId('settled_wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions', 'id', 'workspace_commissions_wallet_tx_fk')
                ->restrictOnDelete();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['beneficiary_user_id', 'status', 'created_at'], 'workspace_commissions_beneficiary_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_workspace_commissions');

        Schema::table('business_workspace_storefronts', function (Blueprint $table): void {
            $table->dropColumn('commission_rate_basis_points');
        });

        Schema::table('vtu_orders', function (Blueprint $table): void {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                // SQLite needs the constrained column list so Laravel can rebuild
                // the table without the foreign key during rollback.
                $table->dropForeign(['business_workspace_storefront_id']);
            } else {
                // MySQL may use the composite index to support the foreign key,
                // so remove the explicitly named constraint before that index.
                $table->dropForeign('vtu_orders_storefront_fk');
            }

            $table->dropIndex('vtu_orders_storefront_status_created_index');
            $table->dropColumn('business_workspace_storefront_id');
        });
    }
};
