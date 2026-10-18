<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vtu_products')) {
            throw new RuntimeException('Cannot extend VTU provider pricing because vtu_products is missing.');
        }

        $columns = [
            'provider_key' => fn (Blueprint $table) => $table->string('provider_key', 32)->nullable(),
            'provider_product_code' => fn (Blueprint $table) => $table->string('provider_product_code', 120)->nullable(),
            'provider_cost_minor' => fn (Blueprint $table) => $table->unsignedBigInteger('provider_cost_minor')->nullable(),
            'data_type' => fn (Blueprint $table) => $table->string('data_type', 40)->nullable(),
            'validity' => fn (Blueprint $table) => $table->string('validity', 80)->nullable(),
            'pricing_by_user_category' => fn (Blueprint $table) => $table->json('pricing_by_user_category')->nullable(),
            'commission_by_user_category' => fn (Blueprint $table) => $table->json('commission_by_user_category')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (!Schema::hasColumn('vtu_products', $name)) {
                Schema::table('vtu_products', function (Blueprint $table) use ($definition): void {
                    $definition($table);
                });
            }
        }

        if (Schema::hasColumn('vtu_products', 'provider_key')
            && !Schema::hasIndex('vtu_products', 'vtu_products_provider_key_index')) {
            Schema::table('vtu_products', function (Blueprint $table): void {
                $table->index('provider_key', 'vtu_products_provider_key_index');
            });
        }

        if (Schema::hasColumn('vtu_products', 'provider_key')
            && !Schema::hasIndex('vtu_products', 'vtu_prod_prov_svc_net_idx')) {
            Schema::table('vtu_products', function (Blueprint $table): void {
                $table->index(['provider_key', 'service_type', 'network'], 'vtu_prod_prov_svc_net_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('vtu_products')) {
            return;
        }

        if (Schema::hasIndex('vtu_products', 'vtu_products_provider_key_index')) {
            Schema::table('vtu_products', function (Blueprint $table): void {
                $table->dropIndex('vtu_products_provider_key_index');
            });
        }

        if (Schema::hasIndex('vtu_products', 'vtu_prod_prov_svc_net_idx')) {
            Schema::table('vtu_products', function (Blueprint $table): void {
                $table->dropIndex('vtu_prod_prov_svc_net_idx');
            });
        }

        $columns = [
            'provider_key',
            'provider_product_code',
            'provider_cost_minor',
            'data_type',
            'validity',
            'pricing_by_user_category',
            'commission_by_user_category',
        ];
        $existingColumns = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn('vtu_products', $column),
        ));

        if ($existingColumns !== []) {
            Schema::table('vtu_products', function (Blueprint $table) use ($existingColumns): void {
                $table->dropColumn($existingColumns);
            });
        }
    }
};
