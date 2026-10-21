<?php

namespace Tests\Feature;

use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\UserLevelPolicy;
use App\Models\UserPermission;
use App\Services\Payments\PaymentIntentService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentRefundWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.paystack.secret_key' => 'sk_test_refund_workflow_secret']);
    }

    private function paidIntent(User $user, int $amount): PaymentIntent
    {
        $wallet = app(WalletAccountService::class)->openForUser($user);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', $amount, 'seed-'.$user->id, description: 'Test funding');
        $intent = app(PaymentIntentService::class)->create($wallet, $amount, 'paystack');
        $intent->forceFill(['status' => 'paid', 'provider_transaction_id' => 'txn-'.$user->id, 'paid_at' => now()])->save();
        return $intent->fresh();
    }

    public function test_refund_request_needs_explicit_approval_permission(): void
    {
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-customer@example.test', 'password' => 'test-password']);
        $intent = $this->paidIntent($customer, 10000);

        $response = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Duplicate funding',
            'idempotency_key' => 'refund-request-1',
        ]);

        $response->assertCreated()->assertJsonPath('data.status', 'requested');
        $this->actingAs($customer)->postJson('/admin/payment-refunds/'.$response->json('data.id').'/approve')->assertForbidden();
        $this->assertSame(10000, (int) $intent->wallet()->first()->fresh()->balance_minor);
        Http::assertNothingSent();
    }

    public function test_authorized_approval_reserves_funds_and_calls_provider(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 123456, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-customer2@example.test', 'password' => 'test-password']);
        $approver = User::query()->create(['name' => 'Approver', 'email' => 'refund-approver@example.test', 'password' => 'test-password']);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Partial refund',
            'idempotency_key' => 'refund-request-2',
        ])->assertCreated();

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$request->json('data.id').'/approve')
            ->assertOk()->assertJsonPath('data.status', 'provider_pending');

        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/refund') && $request['amount'] === 2500);
    }

    public function test_insufficient_available_balance_blocks_refund_without_provider_call(): void
    {
        Http::fake();
        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-low@example.test', 'password' => 'test-password']);
        $approver = User::query()->create(['name' => 'Approver', 'email' => 'refund-low-approver@example.test', 'password' => 'test-password']);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack');
        $intent->forceFill(['status' => 'paid', 'provider_transaction_id' => 'txn-low', 'paid_at' => now()])->save();

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 5000,
            'reason' => 'Balance already spent',
            'idempotency_key' => 'refund-request-low',
        ])->assertCreated();

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$request->json('data.id').'/approve')
            ->assertStatus(409)->assertJsonPath('data.status', 'blocked_insufficient_wallet_funds');
        Http::assertNothingSent();
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_confirmed_refund_failure_compensates_wallet_once_even_on_duplicate_webhook(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 123457, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create(['name' => 'Customer', 'email' => 'refund-fail-customer@example.test', 'password' => 'test-password']);
        $approver = User::query()->create(['name' => 'Approver', 'email' => 'refund-fail-approver@example.test', 'password' => 'test-password']);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 8000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 3000,
            'reason' => 'Provider failure test',
            'idempotency_key' => 'refund-failure-compensation-1',
        ])->assertCreated();
        $refundId = $request->json('data.id');

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$refundId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'provider_pending');
        $this->assertSame(5000, (int) $intent->wallet()->first()->fresh()->balance_minor);

        $payload = [
            'event' => 'refund.failed',
            'data' => [
                'status' => 'failed',
                'transaction_reference' => $intent->reference,
                'refund_reference' => '123457',
                'amount' => 3000,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_refund_workflow_secret');
        $send = fn () => $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw);

        $send()->assertOk();
        $send()->assertOk();

        $this->assertSame(8000, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseHas('payment_refunds', ['public_id' => $refundId, 'status' => 'failed']);
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
    }
    public function test_refund_requester_cannot_approve_their_own_refund_even_with_approval_permission(): void
    {
        Http::fake();
        $customer = User::query()->create([
            'name' => 'Self Approving Customer',
            'email' => 'self-approving-refund@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create([
            'user_id' => $customer->id,
            'permission' => 'refunds.approve',
        ]);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Self approval protection test',
            'idempotency_key' => 'refund-self-approval-protection',
        ])->assertCreated();

        $this->actingAs($customer)
            ->postJson('/admin/payment-refunds/'.$request->json('data.id').'/approve')
            ->assertForbidden();

        $this->assertSame(10000, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $request->json('data.id'),
            'status' => 'requested',
        ]);
        Http::assertNothingSent();
    }


    public function test_refund_webhook_with_unknown_explicit_refund_id_cannot_match_by_transaction_reference(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 7654321, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create([
            'name' => 'Refund Webhook Match Customer',
            'email' => 'refund-webhook-match-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Webhook Match Approver',
            'email' => 'refund-webhook-match-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Unknown provider refund ID test',
            'idempotency_key' => 'refund-unknown-provider-id',
        ])->assertCreated();
        $refundId = $request->json('data.id');

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$refundId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'provider_pending');

        $payload = [
            'event' => 'refund.failed',
            'data' => [
                'status' => 'failed',
                'transaction_reference' => $intent->reference,
                'refund_reference' => '999999999',
                'amount' => 2500,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_refund_workflow_secret');
        $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw)->assertOk();

        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $refundId,
            'status' => 'provider_pending',
            'provider_refund_reference' => '7654321',
        ]);
        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        Http::assertSentCount(1);
    }


    public function test_replaying_refund_approval_cannot_reserve_or_submit_a_second_refund(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 876543, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create([
            'name' => 'Refund Replay Customer',
            'email' => 'refund-replay-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Replay Approver',
            'email' => 'refund-replay-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Approval replay protection',
            'idempotency_key' => 'refund-approval-replay-protection',
        ])->assertCreated();
        $url = '/admin/payment-refunds/'.$request->json('data.id').'/approve';

        $this->actingAs($approver)->postJson($url)
            ->assertOk()
            ->assertJsonPath('data.status', 'provider_pending');

        $this->actingAs($approver)->postJson($url)->assertStatus(409);

        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        Http::assertSentCount(1);
    }

    public function test_failed_refund_recovery_restores_funds_for_disabled_tier_above_wallet_cap(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 223457, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create([
            'name' => 'Disabled Tier Recovery Customer',
            'email' => 'disabled-tier-recovery@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Disabled Tier Recovery Approver',
            'email' => 'disabled-tier-recovery-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 8000);
        $customer->forceFill(['user_level' => 'level_3'])->save();
        UserLevelPolicy::query()->where('level', 'level_3')->update([
            'wallet_balance_limit_minor' => 1000,
            'is_active' => false,
        ]);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 3000,
            'reason' => 'Verify disabled-tier recovery',
            'idempotency_key' => 'disabled-tier-recovery-refund',
        ])->assertCreated();
        $refundId = $request->json('data.id');

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$refundId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'provider_pending');
        $this->assertSame(5000, (int) $intent->wallet()->first()->fresh()->balance_minor);

        $payload = [
            'event' => 'refund.failed',
            'data' => [
                'status' => 'failed',
                'transaction_reference' => $intent->reference,
                'refund_reference' => '223457',
                'amount' => 3000,
                'currency' => 'NGN',
            ],
        ];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha512', $raw, 'sk_test_refund_workflow_secret');
        $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
        ], $raw)->assertOk();

        $this->assertSame(8000, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseHas('payment_refunds', ['public_id' => $refundId, 'status' => 'failed']);
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
    }


    public function test_refund_uses_original_payment_mode_and_fails_closed_after_gateway_mode_switch(): void
    {
        Http::fake();
        $customer = User::query()->create([
            'name' => 'Mode Switch Refund Customer',
            'email' => 'refund-mode-switch-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Mode Switch Refund Approver',
            'email' => 'refund-mode-switch-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(WalletLedgerService::class)->post($wallet->id, 'credit', 10000, 'seed-mode-switch-'.$customer->id, description: 'Synthetic test funding');
        $intent = app(PaymentIntentService::class)->create($wallet, 10000, 'paystack', 'live', 'mode-switch-refund-intent');
        $intent->forceFill([
            'status' => 'paid',
            'provider_transaction_id' => 'synthetic-live-transaction-'.$customer->id,
            'paid_at' => now(),
        ])->save();

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Verify refund mode pinning',
            'idempotency_key' => 'refund-mode-switch-'.$customer->id,
        ])->assertCreated();

        // The active gateway is now test mode while this paid intent was created in live mode.
        \App\Models\IntegrationSetting::query()->updateOrCreate(
            ['key' => 'paystack.mode'],
            ['value' => 'test'],
        );
        \App\Models\IntegrationSetting::query()->updateOrCreate(
            ['key' => 'paystack.secret_key'],
            ['value' => 'sk_test_refund_workflow_secret'],
        );

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$request->json('data.id').'/approve')
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'review_required');

        Http::assertNothingSent();
        $this->assertSame(7500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $request->json('data.id'),
            'status' => 'review_required',
        ]);
    }


    public function test_provider_outage_during_refund_submission_fails_closed_and_replay_cannot_resubmit(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => false,
            'message' => 'Temporary provider outage',
        ], 503)]);

        $customer = User::query()->create([
            'name' => 'Refund Outage Customer',
            'email' => 'refund-outage-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Outage Approver',
            'email' => 'refund-outage-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Provider outage recovery protection',
            'idempotency_key' => 'refund-outage-recovery-'.$customer->id,
        ])->assertCreated();
        $url = '/admin/payment-refunds/'.$request->json('data.id').'/approve';

        $this->actingAs($approver)->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'review_required');

        // A provider error can be ambiguous: keep the reservation and require
        // canonical reconciliation instead of blindly submitting another refund.
        $this->actingAs($approver)->postJson($url)->assertStatus(409);
        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $request->json('data.id'),
            'status' => 'review_required',
            'metadata->settlement_block' => null,
        ]);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        Http::assertSentCount(1);
    }


    public function test_review_required_refund_cannot_be_downgraded_by_a_later_webhook(): void
    {
        Http::fake(['api.paystack.co/refund' => Http::response([
            'status' => true,
            'data' => ['id' => 765433, 'status' => 'pending'],
        ], 200)]);

        $customer = User::query()->create([
            'name' => 'Refund Review Customer',
            'email' => 'refund-review-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Review Approver',
            'email' => 'refund-review-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Review state downgrade protection',
            'idempotency_key' => 'refund-review-no-downgrade',
        ])->assertCreated();
        $refundId = $request->json('data.id');

        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$refundId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'provider_pending');

        $sendEvent = function (array $payload): void {
            $raw = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $signature = hash_hmac('sha512', $raw, 'sk_test_refund_workflow_secret');
            $this->call('POST', '/api/payments/webhooks/paystack', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ], $raw)->assertOk();
        };

        $sendEvent([
            'event' => 'refund.pending',
            'data' => [
                'status' => 'pending',
                'transaction_reference' => $intent->reference,
                'refund_reference' => '765433',
                'amount' => 2400,
                'currency' => 'NGN',
            ],
        ]);
        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $refundId,
            'status' => 'review_required',
        ]);

        $sendEvent([
            'event' => 'refund.pending',
            'data' => [
                'status' => 'pending',
                'transaction_reference' => $intent->reference,
                'refund_reference' => '765433',
                'amount' => 2500,
                'currency' => 'NGN',
            ],
        ]);

        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $refundId,
            'status' => 'review_required',
        ]);
        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        Http::assertSentCount(1);
    }

    public function test_refund_request_idempotency_returns_same_request_and_rejects_changed_payload(): void
    {
        $customer = User::query()->create([
            'name' => 'Refund Idempotency Customer',
            'email' => 'refund-idempotency-customer@example.test',
            'password' => 'test-password',
        ]);
        $intent = $this->paidIntent($customer, 10000);
        $payload = [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Idempotent refund request',
            'idempotency_key' => 'refund-idempotency-key-'.$customer->id,
        ];

        $first = $this->actingAs($customer)->postJson('/wallet/refund-requests', $payload)->assertCreated();
        $replay = $this->actingAs($customer)->postJson('/wallet/refund-requests', $payload)->assertCreated();

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('payment_refunds', 1);

        $changed = $payload;
        $changed['amount_minor'] = 3000;
        $this->actingAs($customer)->postJson('/wallet/refund-requests', $changed)->assertStatus(409);
        $this->assertDatabaseCount('payment_refunds', 1);
        $this->assertSame(10000, (int) $intent->wallet()->first()->fresh()->balance_minor);
        Http::assertNothingSent();
    }

    public function test_unexpected_transport_exception_marks_refund_for_review_without_resubmitting(): void
    {
        $customer = User::query()->create([
            'name' => 'Refund Transport Customer',
            'email' => 'refund-transport-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Transport Approver',
            'email' => 'refund-transport-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Transport exception recovery',
            'idempotency_key' => 'refund-transport-exception-'.$customer->id,
        ])->assertCreated();

        $client = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $client->shouldReceive('refund')->once()->andThrow(new \RuntimeException('Connection lost after refund request dispatch.'));
        app()->instance(\App\Services\Payments\PaystackClient::class, $client);

        $url = '/admin/payment-refunds/'.$request->json('data.id').'/approve';
        $this->actingAs($approver)->postJson($url)
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'review_required');

        $this->actingAs($approver)->postJson($url)->assertStatus(409);
        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $request->json('data.id'),
            'status' => 'review_required',
            'metadata->review_reason' => 'provider_result_unknown',
        ]);
        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }



    public function test_unexpected_reconciliation_lookup_exception_preserves_refund_state(): void
    {
        Http::fake([
            'api.paystack.co/refund' => Http::response([
                'status' => true,
                'data' => ['id' => 7654321, 'status' => 'pending'],
            ], 200),
        ]);

        $customer = User::query()->create([
            'name' => 'Refund Reconcile Customer',
            'email' => 'refund-reconcile-customer@example.test',
            'password' => 'test-password',
        ]);
        $approver = User::query()->create([
            'name' => 'Refund Reconcile Approver',
            'email' => 'refund-reconcile-approver@example.test',
            'password' => 'test-password',
        ]);
        UserPermission::query()->create(['user_id' => $approver->id, 'permission' => 'refunds.approve']);
        $intent = $this->paidIntent($customer, 10000);

        $request = $this->actingAs($customer)->postJson('/wallet/refund-requests', [
            'payment_intent_id' => $intent->public_id,
            'amount_minor' => 2500,
            'reason' => 'Reconciliation lookup exception',
            'idempotency_key' => 'refund-reconcile-exception-'.$customer->id,
        ])->assertCreated();

        $refundId = $request->json('data.id');
        $this->actingAs($approver)->postJson('/admin/payment-refunds/'.$refundId.'/approve')
            ->assertOk()
            ->assertJsonPath('data.status', 'provider_pending');

        $client = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $client->shouldReceive('fetchRefund')->once()->with('7654321', 'test')
            ->andThrow(new \RuntimeException('Unexpected reconciliation transport failure.'));
        app()->instance(\App\Services\Payments\PaystackClient::class, $client);

        $result = app(\App\Services\Payments\PaymentRefundService::class)->reconcileProviderStatus($refundId);

        $this->assertSame(['outcome' => 'unavailable', 'status' => 'provider_pending'], $result);
        $this->assertDatabaseHas('payment_refunds', [
            'public_id' => $refundId,
            'status' => 'provider_pending',
            'provider_refund_reference' => '7654321',
        ]);
        $this->assertSame(7500, (int) $intent->wallet()->first()->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

}
