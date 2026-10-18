<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles the exact schema gaps reported by production on 2026-10-18.
 *
 * This is intentionally additive and idempotent. It preserves existing
 * catalogue and customer data, and never drops or rewrites existing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->repairVtuProductProviderFields();
        $this->repairStreamingSubscriptionCatalogue();
        $this->repairVtuWebsiteRequests();
    }

    private function repairVtuProductProviderFields(): void
    {
        if (!Schema::hasTable('vtu_products')) {
            throw new RuntimeException(
                'Cannot repair VTU provider catalogue fields because vtu_products is missing. Reconcile the base VTU product migration first.'
            );
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

        if (!Schema::hasIndex('vtu_products', 'vtu_products_provider_key_index')) {
            Schema::table('vtu_products', function (Blueprint $table): void {
                $table->index('provider_key', 'vtu_products_provider_key_index');
            });
        }

        if (!Schema::hasIndex('vtu_products', 'vtu_prod_prov_svc_net_idx')) {
            Schema::table('vtu_products', function (Blueprint $table): void {
                $table->index(['provider_key', 'service_type', 'network'], 'vtu_prod_prov_svc_net_idx');
            });
        }
    }

    private function repairStreamingSubscriptionCatalogue(): void
    {
        if (Schema::hasTable('streaming_subscription_products')) {
            return;
        }

        Schema::create('streaming_subscription_products', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 60);
            $table->string('name', 160);
            $table->string('code', 100)->unique();
            $table->string('provider', 60)->nullable();
            $table->string('provider_product_code', 120)->nullable();
            $table->string('duration', 80)->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3)->default('NGN');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('purchase_approved')->default(false);
            $table->timestamps();
            $table->index(['platform', 'is_active']);
            $table->index(['provider', 'provider_product_code'], 'stream_sub_provider_code_idx');
        });
    }

    private function repairVtuWebsiteRequests(): void
    {
        if (!Schema::hasTable('vtu_website_requests')) {
            Schema::create('vtu_website_requests', function (Blueprint $table): void {
                $table->id();
                $table->string('public_id', 24)->unique();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->string('request_type', 32);
                $table->string('business_name', 120)->nullable();
                $table->string('contact_phone', 20);
                $table->unsignedBigInteger('budget_minor')->nullable();
                $table->text('description');
                $table->string('status', 32)->default('submitted');
                $table->text('admin_note')->nullable();
                $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }

        if (!Schema::hasTable('vtu_website_request_events')) {
            if (!Schema::hasTable('vtu_website_requests')) {
                throw new RuntimeException(
                    'Cannot repair VTU website request events because vtu_website_requests is missing.'
                );
            }

            Schema::create('vtu_website_request_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('request_id')->constrained('vtu_website_requests')->restrictOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 40);
                $table->string('old_status', 32)->nullable();
                $table->string('new_status', 32)->nullable();
                $table->text('reason')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['request_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: this migration may repair a live
        // database that already contains valuable catalogue or request data.
    }
};
