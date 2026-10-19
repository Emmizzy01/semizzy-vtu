<?php

namespace Tests\Feature;

use App\Models\ScheduledServicePurchase;
use App\Models\User;
use App\Models\VtuProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ScheduledServicePurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_schedule_a_supported_service_with_pin_and_cancel_it(): void
    {
        $user = User::query()->create([
            'name' => 'Schedule User',
            'email' => 'schedule-user@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill(['transaction_pin_hash' => Hash::make('1234')])->save();

        VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'schedule-data-plan',
            'name' => 'MTN Data Plan',
            'price_minor' => 100000,
            'currency' => 'NGN',
            'is_active' => true,
            'metadata' => ['catalog_source' => 'sogo', 'provider_mappings' => ['sogo' => ['network' => 'mtn', 'variation_code' => 'plan-1']]],
        ]);

        $response = $this->actingAs($user)->postJson('/wallet/scheduled-purchases', [
            'service_type' => 'data',
            'product_code' => 'schedule-data-plan',
            'recipient' => '08012345678',
            'frequency' => 'weekly',
            'next_run_at' => now()->addDay()->toIso8601String(),
            'transaction_pin' => '1234',
        ])->assertCreated()->assertJsonPath('data.status', 'active');

        $id = (int) $response->json('data.id');
        $this->assertDatabaseHas('scheduled_service_purchases', [
            'id' => $id,
            'user_id' => $user->id,
            'product_code' => 'schedule-data-plan',
            'status' => 'active',
        ]);

        $this->actingAs($user)->getJson('/wallet/scheduled-purchases')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->deleteJson('/wallet/scheduled-purchases/'.$id)->assertOk();
        $this->assertDatabaseHas('scheduled_service_purchases', ['id' => $id, 'status' => 'cancelled']);
    }

    public function test_scheduled_purchase_rejects_wrong_pin_and_inactive_product(): void
    {
        $user = User::query()->create([
            'name' => 'Schedule User Two',
            'email' => 'schedule-user-two@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill(['transaction_pin_hash' => Hash::make('1234')])->save();

        VtuProduct::query()->create([
            'service_type' => 'data',
            'network' => 'mtn',
            'code' => 'inactive-schedule-data',
            'name' => 'Inactive Data Plan',
            'price_minor' => 100000,
            'currency' => 'NGN',
            'is_active' => false,
            'metadata' => [],
        ]);

        $payload = [
            'service_type' => 'data',
            'product_code' => 'inactive-schedule-data',
            'recipient' => '08012345678',
            'frequency' => 'daily',
            'next_run_at' => now()->addDay()->toIso8601String(),
            'transaction_pin' => '0000',
        ];
        $this->actingAs($user)->postJson('/wallet/scheduled-purchases', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('scheduled_service_purchases', 0);

        $payload['transaction_pin'] = '1234';
        $this->actingAs($user)->postJson('/wallet/scheduled-purchases', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('scheduled_service_purchases', 0);
    }

    public function test_schedule_is_owner_scoped(): void
    {
        $owner = User::query()->create(['name' => 'Owner', 'email' => 'schedule-owner@example.test', 'password' => 'not-a-real-password']);
        $other = User::query()->create(['name' => 'Other', 'email' => 'schedule-other@example.test', 'password' => 'not-a-real-password']);
        $schedule = ScheduledServicePurchase::query()->create([
            'user_id' => $owner->id,
            'service_type' => 'data',
            'product_code' => 'plan',
            'recipient' => '08012345678',
            'frequency' => 'weekly',
            'next_run_at' => now()->addDay(),
            'status' => 'active',
        ]);

        $this->actingAs($other)->deleteJson('/wallet/scheduled-purchases/'.$schedule->id)->assertNotFound();
        $this->assertDatabaseHas('scheduled_service_purchases', ['id' => $schedule->id, 'status' => 'active']);
    }
}
