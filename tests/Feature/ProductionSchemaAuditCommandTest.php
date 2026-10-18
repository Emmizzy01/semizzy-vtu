<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductionSchemaAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_schema_audit_passes_when_required_schema_is_present(): void
    {
        $this->artisan('semizzy:schema-audit')
            ->expectsOutput('Schema audit passed: all required tables and columns are present. No changes were made.')
            ->assertExitCode(0);
    }

    public function test_schema_audit_reports_missing_table_without_recreating_it(): void
    {
        Schema::dropIfExists('streaming_subscription_products');

        $this->artisan('semizzy:schema-audit')
            ->expectsOutput('Schema audit found missing database objects. No changes were made.')
            ->assertExitCode(1);

        $this->assertFalse(Schema::hasTable('streaming_subscription_products'));
    }
}
