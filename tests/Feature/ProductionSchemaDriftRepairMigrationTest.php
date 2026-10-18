<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionSchemaDriftRepairMigrationTest extends TestCase
{
    public function test_logged_production_schema_gaps_are_repaired_idempotently_without_dropping_existing_tables(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

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

        $providerColumns = [
            'provider_key',
            'provider_product_code',
            'provider_cost_minor',
            'data_type',
            'validity',
            'pricing_by_user_category',
            'commission_by_user_category',
        ];
        $existingColumns = array_values(array_filter(
            $providerColumns,
            fn (string $column): bool => Schema::hasColumn('vtu_products', $column),
        ));

        if ($existingColumns !== []) {
            Schema::table('vtu_products', function (Blueprint $table) use ($existingColumns): void {
                $table->dropColumn($existingColumns);
            });
        }

        Schema::dropIfExists('vtu_website_request_events');
        Schema::dropIfExists('vtu_website_requests');
        Schema::dropIfExists('streaming_subscription_products');

        $migration = require database_path('migrations/2026_10_18_000005_reconcile_logged_production_schema_drift.php');
        $migration->up();
        $migration->up();

        foreach ($providerColumns as $column) {
            $this->assertTrue(Schema::hasColumn('vtu_products', $column), "Expected vtu_products.{$column} to be restored.");
        }

        $this->assertTrue(Schema::hasIndex('vtu_products', 'vtu_products_provider_key_index'));
        $this->assertTrue(Schema::hasIndex('vtu_products', 'vtu_prod_prov_svc_net_idx'));
        $this->assertTrue(Schema::hasTable('streaming_subscription_products'));
        $this->assertTrue(Schema::hasTable('vtu_website_requests'));
        $this->assertTrue(Schema::hasTable('vtu_website_request_events'));
    }
}
