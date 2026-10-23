<?php

namespace Tests\Feature;

use App\Models\ServiceBeneficiary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceBeneficiaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_list_and_remove_own_service_beneficiaries(): void
    {
        $user = User::query()->create([
            'name' => 'Beneficiary User',
            'email' => 'beneficiary-user@example.test',
            'password' => 'not-a-real-password',
        ]);

        $created = $this->actingAs($user)->postJson('/wallet/service-beneficiaries', [
            'service_type' => 'tv-subscription',
            'label' => 'Home decoder',
            'recipient' => '1234 5678 9012',
            'network' => 'DStv',
            'product_code' => 'dstv-product',
            'service_details' => ['subscription_type' => 'change', 'transaction_pin' => '1234'],
        ])->assertCreated()
            ->assertJsonPath('data.recipient', '123456789012')
            ->assertJsonPath('data.label', 'Home decoder');

        $id = (int) $created->json('data.id');
        $this->assertDatabaseHas('service_beneficiaries', [
            'id' => $id,
            'user_id' => $user->id,
            'service_type' => 'tv-subscription',
            'recipient' => '123456789012',
        ]);
        $this->assertDatabaseMissing('service_beneficiaries', [
            'id' => $id,
            'service_details->transaction_pin' => '1234',
        ]);

        $this->actingAs($user)->getJson('/wallet/service-beneficiaries?service_type=tv-subscription')
            ->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($user)->deleteJson('/wallet/service-beneficiaries/'.$id)
            ->assertOk();
        $this->assertDatabaseMissing('service_beneficiaries', ['id' => $id]);
    }

    public function test_saved_beneficiary_does_not_imply_purchase_readiness_for_unverified_services(): void
    {
        $user = User::query()->create([
            'name' => 'Unverified Beneficiary User',
            'email' => 'unverified-beneficiary@example.test',
            'password' => 'not-a-real-password',
        ]);

        $created = $this->actingAs($user)->postJson('/wallet/service-beneficiaries', [
            'service_type' => 'government-service',
            'label' => 'Document recipient',
            'recipient' => 'ABC12345678',
        ])->assertCreated()
            ->assertJsonPath('data.purchase_readiness', 'not_verified');

        $this->actingAs($user)->getJson('/wallet/service-beneficiaries?service_type=government-service')
            ->assertOk()
            ->assertJsonPath('data.0.purchase_readiness', 'not_verified');
    }

    public function test_beneficiary_cannot_be_removed_or_viewed_by_another_user(): void
    {
        $owner = User::query()->create([
            'name' => 'Owner User',
            'email' => 'beneficiary-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $other = User::query()->create([
            'name' => 'Other User',
            'email' => 'beneficiary-other@example.test',
            'password' => 'not-a-real-password',
        ]);
        $record = ServiceBeneficiary::query()->create([
            'user_id' => $owner->id,
            'service_type' => 'airtime',
            'label' => 'My phone',
            'recipient' => '08012345678',
        ]);

        $this->actingAs($other)->deleteJson('/wallet/service-beneficiaries/'.$record->id)->assertNotFound();
        $this->actingAs($other)->getJson('/wallet/service-beneficiaries')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('service_beneficiaries', ['id' => $record->id, 'user_id' => $owner->id]);
    }

    public function test_duplicate_labels_are_rejected_per_user_and_service(): void
    {
        $user = User::query()->create([
            'name' => 'Duplicate User',
            'email' => 'beneficiary-duplicate@example.test',
            'password' => 'not-a-real-password',
        ]);
        $payload = [
            'service_type' => 'airtime',
            'label' => 'Main phone',
            'recipient' => '08012345678',
        ];
        $this->actingAs($user)->postJson('/wallet/service-beneficiaries', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/wallet/service-beneficiaries', $payload)->assertStatus(409);
    }
}
