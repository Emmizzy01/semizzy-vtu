<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Wallets\BankPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class BankPayoutSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_bank_payouts_are_disabled_by_default_and_do_not_debit_wallet(): void
    {
        $user = User::query()->create([
            'name' => 'Bank Payout User',
            'email' => 'bank-payout-user@example.test',
            'password' => 'not-a-real-password',
        ]);

        try {
            app(BankPayoutService::class)->requestPayout($user, 999, 25000, 'bank-payout-disabled-001');
            $this->fail('Payout should be blocked until the administrator enables provider payouts.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('disabled by the administrator', $exception->getMessage());
        }

        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('bank_payouts', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_stale_queued_payout_is_quarantined_without_provider_retry_or_refund(): void
    {
        $user = User::query()->create([
            'name' => 'Stale Queued Payout User',
            'email' => 'stale-queued-payout@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Recovery Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $user->name,
            'provider_recipient_code' => 'RCP_stale_queued_test',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $makePayout = function (string $suffix, string $status, $createdAt) use ($user, $beneficiary) {
            return \App\Models\BankPayout::query()->create([
                'public_id' => (string) \Illuminate\Support\Str::uuid(),
                'user_id' => $user->id,
                'bank_beneficiary_id' => $beneficiary->id,
                'currency' => 'NGN',
                'amount_minor' => 10000,
                'fee_minor' => 0,
                'status' => $status,
                'idempotency_key' => 'stale-queued-'.$suffix,
                'request_hash' => hash('sha256', $suffix),
                'provider_reference' => 'semizzy_stale_queued_'.$suffix,
                'wallet_transaction_id' => null,
                'metadata' => ['provider' => 'paystack'],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        };

        $stale = $makePayout('old', 'queued', now()->subHour());
        $recent = $makePayout('recent', 'queued', now()->subMinutes(5));
        $pending = $makePayout('provider-pending', 'provider_pending', now()->subHour());

        $exitCode = \Illuminate\Support\Facades\Artisan::call('bank-payouts:quarantine-stale-queued', [
            '--minutes' => 30,
            '--limit' => 100,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('reconciliation_required', $stale->fresh()->status);
        $this->assertStringContainsString('before any manual retry', $stale->fresh()->failure_reason);
        $this->assertSame('stale_queued_quarantined', $stale->fresh()->metadata['recovery_action']);
        $this->assertSame('queued', $recent->fresh()->status);
        $this->assertSame('provider_pending', $pending->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
    }

    public function test_http_payout_endpoint_rejects_wrong_pin_before_creating_payout(): void
    {
        $user = User::query()->create([
            'name' => 'PIN Protected Payout User',
            'email' => 'pin-protected-payout@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill([
            'transaction_pin_hash' => \Illuminate\Support\Facades\Hash::make('4928'),
        ])->save();

        $this->actingAs($user)->postJson('/wallet/bank-transfer', [
            'beneficiary_id' => 999,
            'amount_minor' => 25000,
            'transaction_pin' => '1111',
            'idempotency_key' => 'payout-wrong-pin-test-001',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Transaction PIN is incorrect.');

        $this->assertDatabaseCount('bank_payouts', 0);
        $this->assertDatabaseCount('wallets', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'pin_verification',
            'outcome' => 'failed',
        ]);
    }

    public function test_bank_beneficiary_account_number_is_encrypted_at_rest(): void
    {
        $user = User::query()->create([
            'name' => 'Beneficiary Owner',
            'email' => 'beneficiary-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Example Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => 'Beneficiary Owner',
            'provider_recipient_code' => 'RCP_test_123456',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $stored = \Illuminate\Support\Facades\DB::table('bank_beneficiaries')->where('id', $beneficiary->id)->value('account_number');
        $this->assertNotSame('0123456789', $stored);
        $this->assertSame('0123456789', $beneficiary->fresh()->account_number);
    }
    public function test_user_can_remove_own_saved_bank_account_when_it_has_no_transfer_history(): void
    {
        $user = User::query()->create([
            'name' => 'Account Owner',
            'email' => 'account-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Example Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => 'Account Owner',
            'provider_recipient_code' => 'RCP_remove_test_123',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        app(BankPayoutService::class)->removeBeneficiary($user, $beneficiary->id);

        $this->assertDatabaseMissing('bank_beneficiaries', ['id' => $beneficiary->id]);
    }

    public function test_saved_bank_account_with_payout_history_cannot_be_removed(): void
    {
        $user = User::query()->create([
            'name' => 'History Owner',
            'email' => 'history-owner@example.test',
            'password' => 'not-a-real-password',
        ]);

        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '998',
            'bank_name' => 'History Test Bank',
            'account_number' => '9876543210',
            'account_number_last4' => '3210',
            'account_name' => 'History Owner',
            'provider_recipient_code' => 'RCP_history_test_123',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        \App\Models\BankPayout::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'bank_beneficiary_id' => $beneficiary->id,
            'currency' => 'NGN',
            'amount_minor' => 10000,
            'fee_minor' => 0,
            'status' => 'provider_pending',
            'idempotency_key' => 'bank-history-test-001',
            'request_hash' => hash('sha256', 'test'),
            'provider_reference' => 'semizzy_history_test_001',
            'wallet_transaction_id' => null,
            'metadata' => ['provider' => 'test'],
        ]);

        try {
            app(BankPayoutService::class)->removeBeneficiary($user, $beneficiary->id);
            $this->fail('An account referenced by payout history must be retained.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('transfer history', $exception->getMessage());
        }

        $this->assertDatabaseHas('bank_beneficiaries', ['id' => $beneficiary->id]);
    }


    public function test_payout_history_is_scoped_to_the_authenticated_user(): void
    {
        $owner = User::query()->create(['name' => 'Payout Owner', 'email' => 'payout-owner-scope@example.test', 'password' => 'not-a-real-password']);
        $other = User::query()->create(['name' => 'Other Payout User', 'email' => 'payout-other-scope@example.test', 'password' => 'not-a-real-password']);
        $makePayout = function (User $user, string $suffix): void {
            $beneficiary = \App\Models\BankBeneficiary::query()->create([
                'user_id' => $user->id, 'bank_code' => '999', 'bank_name' => 'Scope Test Bank',
                'account_number' => '0123456789', 'account_number_last4' => '6789',
                'account_name' => $user->name, 'provider_recipient_code' => 'RCP_scope_'.$suffix,
                'verification_status' => 'verified', 'verified_at' => now(),
            ]);
            \App\Models\BankPayout::query()->create([
                'public_id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $user->id,
                'bank_beneficiary_id' => $beneficiary->id, 'currency' => 'NGN', 'amount_minor' => 10000,
                'fee_minor' => 0, 'status' => 'provider_pending', 'idempotency_key' => 'payout-scope-'.$suffix,
                'request_hash' => hash('sha256', $suffix), 'provider_reference' => 'semizzy_scope_'.$suffix,
                'wallet_transaction_id' => null, 'metadata' => ['provider' => 'test'],
            ]);
        };
        $makePayout($owner, 'owner');
        $makePayout($other, 'other');

        $this->actingAs($owner)->getJson('/wallet/bank-transfer/history')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amount_minor', 10000);
        $this->actingAs($other)->getJson('/wallet/bank-transfer/history')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertDatabaseCount('bank_payouts', 2);
    }

    public function test_duplicate_bank_account_is_reused_for_owner_and_rejected_for_another_user_before_provider_recipient_creation(): void
    {
        $owner = User::query()->create([
            'name' => 'Duplicate Account Owner',
            'email' => 'duplicate-account-owner@example.test',
            'password' => 'not-a-real-password',
        ]);
        $other = User::query()->create([
            'name' => 'Duplicate Account Other',
            'email' => 'duplicate-account-other@example.test',
            'password' => 'not-a-real-password',
        ]);

        $saved = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $owner->id,
            'bank_code' => '999',
            'bank_name' => 'Duplicate Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $owner->name,
            'provider_recipient_code' => 'RCP_duplicate_account_existing',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('listNigerianBanks')->twice()->andReturn([
            ['name' => 'Duplicate Test Bank', 'code' => '999'],
        ]);
        $paystack->shouldNotReceive('resolveNigerianAccount');
        $paystack->shouldNotReceive('createTransferRecipient');
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $service = app(BankPayoutService::class);
        $reused = $service->addBeneficiary($owner, '999', '0123456789');
        $this->assertSame($saved->id, $reused->id);

        try {
            $service->addBeneficiary($other, '999', '0123456789');
            $this->fail('A saved bank account must not be attached to a second Semizzy account.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('already associated with another Semizzy account', $exception->getMessage());
        }

        $this->assertDatabaseCount('bank_beneficiaries', 1);
    }

    public function test_repeated_payout_request_debits_once_and_submits_to_provider_once(): void
    {
        $user = User::query()->create([
            'name' => 'Idempotent Payout User',
            'email' => 'idempotent-payout@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id, 'bank_code' => '999', 'bank_name' => 'Idempotency Test Bank',
            'account_number' => '0123456789', 'account_number_last4' => '6789',
            'account_name' => $user->name, 'provider_recipient_code' => 'RCP_idempotent_payout',
            'verification_status' => 'verified', 'verified_at' => now(),
        ]);
        app(\App\Services\IntegrationSettings::class)->set('paystack.transfers_enabled', '1', $user->id);
        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user);
        app(\App\Services\Wallets\WalletLedgerService::class)->post($wallet->id, 'credit', 50000, 'payout-idempotency-seed-001');

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('mode')->once()->andReturn('test');
        $paystack->shouldReceive('initiateBankTransfer')->once()->andReturnUsing(
            static fn (int $amount, string $recipient, string $reference, string $reason): array => [
                'reference' => $reference, 'transfer_code' => 'TRF_payout_idempotency_once', 'status' => 'pending',
            ]
        );
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $service = app(BankPayoutService::class);
        $first = $service->requestPayout($user, $beneficiary->id, 12500, 'payout-idempotency-once-001');
        $again = $service->requestPayout($user, $beneficiary->id, 12500, 'payout-idempotency-once-001');

        $this->assertSame($first->id, $again->id);
        $this->assertSame('provider_pending', $again->status);
        $this->assertSame(37500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('bank_payouts', 1);
        $this->assertDatabaseCount('wallet_transactions', 2);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
    }

    public function test_verified_reversal_after_success_refunds_wallet_once(): void
    {
        $user = User::query()->create([
            'name' => 'Reversed Payout User',
            'email' => 'reversed-payout@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Reversal Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $user->name,
            'provider_recipient_code' => 'RCP_reversal_test',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
        app(\App\Services\IntegrationSettings::class)->set('paystack.transfers_enabled', '1', $user->id);
        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user);
        app(\App\Services\Wallets\WalletLedgerService::class)->post($wallet->id, 'credit', 50000, 'reversal-payout-seed-001');

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('mode')->once()->andReturn('test');
        $paystack->shouldReceive('initiateBankTransfer')->once()->andReturnUsing(
            static fn (int $amount, string $recipient, string $reference, string $reason): array => [
                'reference' => $reference,
                'transfer_code' => 'TRF_reversal_test_001',
                'status' => 'pending',
            ]
        );
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);
        $payout = app(BankPayoutService::class)->requestPayout(
            $user, $beneficiary->id, 12500, 'reversal-payout-request-001'
        );
        $payout->forceFill(['status' => 'succeeded'])->save();

        $paystack->shouldReceive('fetchBankTransfer')->once()->with('TRF_reversal_test_001')->andReturn([
            'reference' => $payout->provider_reference,
            'transfer_code' => 'TRF_reversal_test_001',
            'status' => 'reversed',
        ]);

        $service = app(\App\Services\Wallets\BankPayoutReconciliationService::class);
        $payload = ['data' => [
            'reference' => $payout->provider_reference,
            'transfer_code' => 'TRF_reversal_test_001',
        ]];
        $result = $service->applyPaystackEvent('transfer.reversed', $payload);
        $duplicate = $service->applyPaystackEvent('transfer.reversed', $payload);

        $this->assertSame('failed_refunded', $result['outcome']);
        $this->assertFalse($result['duplicate']);
        $this->assertSame('failed_refunded', $duplicate['outcome']);
        $this->assertTrue($duplicate['duplicate']);
        $this->assertSame(50000, (int) $wallet->fresh()->balance_minor);
        $this->assertSame('failed_refunded', $payout->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 3);
        $this->assertDatabaseCount('wallet_ledger_entries', 3);
        $paystack->shouldHaveReceived('fetchBankTransfer')->once();
    }


    public function test_payout_idempotency_key_cannot_be_reused_for_different_amount(): void
    {
        $user = User::query()->create([
            'name' => 'Payout Conflict User',
            'email' => 'payout-conflict@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id, 'bank_code' => '999', 'bank_name' => 'Conflict Test Bank',
            'account_number' => '0123456789', 'account_number_last4' => '6789',
            'account_name' => $user->name, 'provider_recipient_code' => 'RCP_payout_conflict',
            'verification_status' => 'verified', 'verified_at' => now(),
        ]);
        app(\App\Services\IntegrationSettings::class)->set('paystack.transfers_enabled', '1', $user->id);
        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user);
        app(\App\Services\Wallets\WalletLedgerService::class)->post($wallet->id, 'credit', 50000, 'payout-conflict-seed-001');

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('mode')->once()->andReturn('test');
        $paystack->shouldReceive('initiateBankTransfer')->once()->andReturnUsing(
            static fn (int $amount, string $recipient, string $reference, string $reason): array => [
                'reference' => $reference, 'transfer_code' => 'TRF_payout_conflict_once', 'status' => 'pending',
            ]
        );
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $service = app(BankPayoutService::class);
        $service->requestPayout($user, $beneficiary->id, 12500, 'payout-conflict-key-001');

        $this->expectException(\App\Exceptions\Wallet\IdempotencyConflict::class);
        $service->requestPayout($user, $beneficiary->id, 13000, 'payout-conflict-key-001');
    }


    public function test_uncertain_provider_exception_marks_payout_for_reconciliation_without_retrying(): void
    {
        $user = User::query()->create([
            'name' => 'Unknown Outcome User',
            'email' => 'unknown-payout-outcome@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id, 'bank_code' => '999', 'bank_name' => 'Unknown Outcome Bank',
            'account_number' => '0123456789', 'account_number_last4' => '6789',
            'account_name' => $user->name, 'provider_recipient_code' => 'RCP_unknown_outcome',
            'verification_status' => 'verified', 'verified_at' => now(),
        ]);
        app(\App\Services\IntegrationSettings::class)->set('paystack.transfers_enabled', '1', $user->id);
        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user);
        app(\App\Services\Wallets\WalletLedgerService::class)->post($wallet->id, 'credit', 50000, 'unknown-payout-seed-001');

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('mode')->once()->andReturn('test');
        $paystack->shouldReceive('initiateBankTransfer')->once()->andThrow(new \RuntimeException('Connection lost after request dispatch'));
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $service = app(BankPayoutService::class);
        $first = $service->requestPayout($user, $beneficiary->id, 12500, 'payout-unknown-outcome-001');
        $again = $service->requestPayout($user, $beneficiary->id, 12500, 'payout-unknown-outcome-001');

        $this->assertSame($first->id, $again->id);
        $this->assertSame('reconciliation_required', $first->status);
        $this->assertSame('reconciliation_required', $again->status);
        $this->assertSame(37500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('bank_payouts', 1);
        $this->assertDatabaseCount('wallet_ledger_entries', 2);
        $this->assertDatabaseHas('bank_payouts', [
            'id' => $first->id,
            'status' => 'reconciliation_required',
        ]);
    }

    public function test_unexpected_provider_lookup_exception_keeps_payout_reserved_for_reconciliation(): void
    {
        $user = User::query()->create([
            'name' => 'Payout Reconciliation User',
            'email' => 'payout-reconciliation-unknown@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Reconciliation Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $user->name,
            'provider_recipient_code' => 'RCP_reconciliation_unknown',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
        $payout = \App\Models\BankPayout::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'bank_beneficiary_id' => $beneficiary->id,
            'currency' => 'NGN',
            'amount_minor' => 12500,
            'fee_minor' => 0,
            'status' => 'provider_pending',
            'idempotency_key' => 'payout-reconciliation-unknown-001',
            'request_hash' => hash('sha256', 'payout-reconciliation-unknown'),
            'provider_reference' => 'semizzy_reconciliation_unknown_001',
            'provider_transfer_code' => 'TRF_reconciliation_unknown',
            'wallet_transaction_id' => null,
            'metadata' => ['gateway_mode' => 'test'],
        ]);

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('fetchBankTransfer')
            ->once()
            ->with('TRF_reconciliation_unknown')
            ->andThrow(new \RuntimeException('Unexpected provider lookup failure.'));
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $result = app(\App\Services\Wallets\BankPayoutReconciliationService::class)
            ->applyPaystackEvent('transfer.success', [
                'data' => [
                    'reference' => 'semizzy_reconciliation_unknown_001',
                    'transfer_code' => 'TRF_reconciliation_unknown',
                ],
            ]);

        $this->assertSame('reconciliation_required', $result['outcome']);
        $this->assertSame('reconciliation_required', $payout->fresh()->status);
        $this->assertStringContainsString('funds remain reserved', $payout->fresh()->failure_reason);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $paystack->shouldHaveReceived('fetchBankTransfer')->once();
    }


    public function test_active_transaction_pin_lockout_blocks_payout_before_any_debit_or_provider_request(): void
    {
        $user = User::query()->create([
            'name' => 'Locked PIN Payout User',
            'email' => 'locked-pin-payout@example.test',
            'password' => 'not-a-real-password',
        ]);
        $user->forceFill([
            'transaction_pin_hash' => \Illuminate\Support\Facades\Hash::make('4928'),
            'transaction_pin_failed_attempts' => 5,
            'transaction_pin_locked_until' => now()->addMinutes(10),
        ])->save();
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Locked PIN Test Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $user->name,
            'provider_recipient_code' => 'RCP_locked_pin_payout',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
        app(\App\Services\IntegrationSettings::class)->set('paystack.transfers_enabled', '1', $user->id);
        $wallet = app(\App\Services\Wallets\WalletAccountService::class)->openForUser($user, 'NGN');
        app(\App\Services\Wallets\WalletLedgerService::class)->post(
            walletId: $wallet->id,
            type: 'credit',
            amountMinor: 50000,
            idempotencyKey: 'locked-pin-payout-opening-credit',
        );

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldNotReceive('initiateBankTransfer');
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $this->actingAs($user)->postJson('/wallet/bank-transfer', [
            'beneficiary_id' => $beneficiary->id,
            'amount_minor' => 12500,
            'transaction_pin' => '4928',
            'idempotency_key' => 'locked-pin-payout-attempt-001',
        ])->assertStatus(422)
          ->assertJsonPath('message', 'Transaction PIN is temporarily locked. Try again later.');

        $this->assertSame(50000, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('bank_payouts', 0);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $this->assertDatabaseHas('transaction_security_events', [
            'user_id' => $user->id,
            'event_type' => 'pin_verification',
            'outcome' => 'locked',
        ]);
    }


    public function test_uncertain_stale_webhook_does_not_overwrite_concurrently_confirmed_success(): void
    {
        $user = User::query()->create([
            'name' => 'Concurrent Payout Settlement User',
            'email' => 'concurrent-payout-settlement@example.test',
            'password' => 'not-a-real-password',
        ]);
        $beneficiary = \App\Models\BankBeneficiary::query()->create([
            'user_id' => $user->id,
            'bank_code' => '999',
            'bank_name' => 'Concurrent Settlement Bank',
            'account_number' => '0123456789',
            'account_number_last4' => '6789',
            'account_name' => $user->name,
            'provider_recipient_code' => 'RCP_concurrent_settlement',
            'verification_status' => 'verified',
            'verified_at' => now(),
        ]);
        $payout = \App\Models\BankPayout::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'bank_beneficiary_id' => $beneficiary->id,
            'currency' => 'NGN',
            'amount_minor' => 12500,
            'fee_minor' => 0,
            'status' => 'provider_pending',
            'idempotency_key' => 'concurrent-payout-settlement-001',
            'request_hash' => hash('sha256', 'concurrent-payout-settlement'),
            'provider_reference' => 'semizzy_concurrent_settlement_001',
            'provider_transfer_code' => 'TRF_concurrent_settlement',
            'wallet_transaction_id' => null,
            'metadata' => ['gateway_mode' => 'test'],
        ]);

        $paystack = \Mockery::mock(\App\Services\Payments\PaystackClient::class);
        $paystack->shouldReceive('fetchBankTransfer')
            ->once()
            ->with('TRF_concurrent_settlement')
            ->andReturnUsing(function () use ($payout): array {
                // Simulate another verified webhook winning while this lookup is in flight.
                $payout->forceFill(['status' => 'succeeded'])->save();

                throw new \RuntimeException('Stale webhook lookup lost its connection.');
            });
        $this->app->instance(\App\Services\Payments\PaystackClient::class, $paystack);

        $result = app(\App\Services\Wallets\BankPayoutReconciliationService::class)
            ->applyPaystackEvent('transfer.failed', [
                'data' => [
                    'reference' => 'semizzy_concurrent_settlement_001',
                    'transfer_code' => 'TRF_concurrent_settlement',
                ],
            ]);

        $this->assertSame('succeeded', $result['outcome']);
        $this->assertTrue($result['duplicate']);
        $this->assertSame('succeeded', $payout->fresh()->status);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('wallet_ledger_entries', 0);
        $paystack->shouldHaveReceived('fetchBankTransfer')->once();
    }

}
