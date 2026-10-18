<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\UserLevelPolicy;
use App\Models\ManualDeposit;
use App\Models\PlatformFeatureControl;
use App\Models\Wallet;
use App\Services\Wallets\WalletAccountService;
use App\Services\IntegrationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ManualDepositWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_submission_stays_pending_and_does_not_credit_wallet(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Deposit Customer', 'email' => 'deposit-customer@example.test', 'password' => 'not-a-real-password']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(IntegrationSettings::class)->set('manual_deposit.enabled', '1', $customer->id);

        $this->actingAs($customer)->post('/wallet/manual-deposits', [
            'amount_minor' => 250000,
            'transfer_reference' => 'BANK-TRANSFER-0001',
            'evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertRedirect('/wallet/manual-deposits');

        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('manual_deposits', ['user_id' => $customer->id, 'status' => 'pending', 'transfer_reference' => 'BANK-TRANSFER-0001']);
    }


    public function test_manual_deposits_are_disabled_until_admin_configures_instructions(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Safe Defaults Customer', 'email' => 'safe-deposit-default@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($customer)->get('/wallet/manual-deposits')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Wallet/ManualDeposit')->where('instructions.enabled', false));

        $this->actingAs($customer)->post('/wallet/manual-deposits', [
            'amount_minor' => 250000,
            'transfer_reference' => 'SAFE-DEFAULT-TRANSFER-001',
            'evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertForbidden();
        $this->assertDatabaseCount('manual_deposits', 0);
    }

    public function test_missing_manual_deposit_feature_control_fails_closed(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Missing Feature Customer', 'email' => 'missing-manual-feature@example.test', 'password' => 'not-a-real-password']);
        app(IntegrationSettings::class)->set('manual_deposit.enabled', '1', $customer->id);
        PlatformFeatureControl::query()->where('feature_key', 'service.manual_deposit')->delete();

        $this->actingAs($customer)->get('/wallet/manual-deposits')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Wallet/ManualDeposit')->where('instructions.enabled', false));

        $this->actingAs($customer)->post('/wallet/manual-deposits', [
            'amount_minor' => 250000,
            'transfer_reference' => 'MISSING-FEATURE-TRANSFER-001',
            'evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertForbidden();

        $this->assertDatabaseCount('manual_deposits', 0);
    }

    public function test_duplicate_receipt_evidence_is_flagged_and_cannot_be_approved(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Duplicate Receipt Customer', 'email' => 'duplicate-receipt@example.test', 'password' => 'not-a-real-password']);
        $receiptContent = '%PDF-1.4\nSemizzy duplicate receipt test\n%%EOF';
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(IntegrationSettings::class)->set('manual_deposit.enabled', '1', $customer->id);

        $this->actingAs($customer)->post('/wallet/manual-deposits', [
            'amount_minor' => 150000,
            'transfer_reference' => 'DUPLICATE-RECEIPT-0001',
            'evidence' => UploadedFile::fake()->createWithContent('receipt.pdf', $receiptContent),
        ])->assertRedirect('/wallet/manual-deposits');

        $this->actingAs($customer)->post('/wallet/manual-deposits', [
            'amount_minor' => 150000,
            'transfer_reference' => 'DUPLICATE-RECEIPT-0002',
            'evidence' => UploadedFile::fake()->createWithContent('receipt.pdf', $receiptContent),
        ])->assertRedirect('/wallet/manual-deposits');

        $firstDeposit = ManualDeposit::query()->where('transfer_reference', 'DUPLICATE-RECEIPT-0001')->firstOrFail();
        $deposit = ManualDeposit::query()->where('transfer_reference', 'DUPLICATE-RECEIPT-0002')->firstOrFail();
        $this->assertDatabaseHas('manual_deposit_evidence_fingerprints', [
            'evidence_sha256' => hash('sha256', $receiptContent),
            'first_manual_deposit_id' => $firstDeposit->id,
        ]);
        $this->assertTrue((bool) $deposit->duplicate_evidence);
        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('manual_deposit_audits', ['manual_deposit_id' => $deposit->id, 'event_type' => 'duplicate_evidence_detected', 'outcome' => 'flagged']);
    }

    public function test_customer_cannot_review_deposits_and_admin_must_view_evidence_before_crediting(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Deposit Customer', 'email' => 'deposit-customer2@example.test', 'password' => 'not-a-real-password']);
        $admin = User::query()->create(['name' => 'Finance Admin', 'email' => 'deposit-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        $path = UploadedFile::fake()->image('proof.png')->store('manual-deposits', 'local');
        $deposit = ManualDeposit::query()->create([
            'public_id' => 'a5a56f32-3cc5-42bd-9c28-4b4f94c6ad23', 'user_id' => $customer->id, 'wallet_id' => $wallet->id,
            'amount_minor' => 12500, 'transfer_reference' => 'TRANSFER-UNIQUE-0002', 'evidence_path' => $path,
            'evidence_sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'status' => 'pending', 'idempotency_key' => 'manual-deposit:test-0002',
        ]);

        $this->actingAs($customer)->get('/admin/manual-deposits')->assertForbidden();
        $this->actingAs($admin)->post('/admin/manual-deposits/'.$deposit->public_id.'/review', ['decision' => 'approve', 'reason' => 'Verified bank transfer'])->assertStatus(422);
        $this->actingAs($admin)->get('/admin/manual-deposits/'.$deposit->public_id.'/evidence')->assertOk();
        $this->actingAs($admin)->post('/admin/manual-deposits/'.$deposit->public_id.'/review', ['decision' => 'approve', 'reason' => 'Verified bank transfer'])->assertRedirect();

        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('manual_deposits', ['id' => $deposit->id, 'status' => 'approved']);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);

        // Replaying the same approval must fail because the deposit is no longer pending.
        $this->actingAs($admin)->post('/admin/manual-deposits/'.$deposit->public_id.'/review', [
            'decision' => 'approve',
            'reason' => 'Repeat approval attempt',
        ])->assertStatus(409);

        $this->assertSame(12500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
    }

    public function test_over_limit_manual_deposit_is_preserved_for_review_without_credit(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Capped Deposit Customer', 'email' => 'capped-deposit@example.test', 'password' => 'not-a-real-password']);
        $admin = User::query()->create(['name' => 'Capped Deposit Admin', 'email' => 'capped-deposit-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(\App\Services\Wallets\WalletLedgerService::class)->post($wallet->id, 'credit', 9500, 'manual-cap-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update(['wallet_balance_limit_minor' => 10000, 'is_active' => true]);
        $path = UploadedFile::fake()->image('proof.png')->store('manual-deposits', 'local');
        $deposit = ManualDeposit::query()->create([
            'public_id' => 'a6a56f32-3cc5-42bd-9c28-4b4f94c6ad24', 'user_id' => $customer->id,
            'wallet_id' => $wallet->id, 'amount_minor' => 1000, 'transfer_reference' => 'TRANSFER-LIMIT-0001',
            'evidence_path' => $path, 'evidence_sha256' => hash_file('sha256', Storage::disk('local')->path($path)),
            'status' => 'pending', 'idempotency_key' => 'manual-deposit:limit-test-0001',
            'evidence_viewed_at' => now(), 'evidence_viewed_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)->post('/admin/manual-deposits/'.$deposit->public_id.'/review', [
            'decision' => 'approve', 'reason' => 'Verified bank transfer evidence',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(9500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('manual_deposits', ['id' => $deposit->id, 'status' => 'review_required']);
        $this->assertDatabaseHas('manual_deposit_audits', ['manual_deposit_id' => $deposit->id, 'event_type' => 'wallet_limit_review', 'outcome' => 'review_required']);
        $this->assertDatabaseCount('wallet_ledger_entries', 1);
    }

    public function test_manual_deposit_that_exceeds_wallet_cap_is_held_for_reconciliation(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Cap Customer', 'email' => 'cap-customer@example.test', 'password' => 'not-a-real-password']);
        $admin = User::query()->create(['name' => 'Cap Admin', 'email' => 'cap-admin@example.test', 'password' => 'not-a-real-password']);
        UserPermission::query()->create(['user_id' => $admin->id, 'permission' => 'staff.permissions.manage']);
        $wallet = app(WalletAccountService::class)->openForUser($customer);
        app(\App\Services\Wallets\WalletLedgerService::class)->post($wallet->id, 'credit', 9500, 'cap-deposit-seed');
        UserLevelPolicy::query()->where('level', 'level_1')->update(['wallet_balance_limit_minor' => 10000, 'is_active' => true]);
        $path = UploadedFile::fake()->image('proof.png')->store('manual-deposits', 'local');
        $deposit = ManualDeposit::query()->create([
            'public_id' => 'b6b56f32-3cc5-42bd-9c28-4b4f94c6ad24', 'user_id' => $customer->id, 'wallet_id' => $wallet->id,
            'amount_minor' => 1000, 'transfer_reference' => 'CAP-TRANSFER-0001', 'evidence_path' => $path,
            'evidence_sha256' => hash_file('sha256', Storage::disk('local')->path($path)), 'status' => 'pending',
            'idempotency_key' => 'manual-deposit:cap-0001', 'evidence_viewed_at' => now(), 'evidence_viewed_by_user_id' => $admin->id,
        ]);
        $this->actingAs($admin)->post('/admin/manual-deposits/'.$deposit->public_id.'/review', [
            'decision' => 'approve', 'reason' => 'Verified transfer evidence',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(9500, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('manual_deposits', ['id' => $deposit->id, 'status' => 'review_required']);
        $this->assertDatabaseHas('manual_deposit_audits', ['manual_deposit_id' => $deposit->id, 'event_type' => 'wallet_limit_review']);
    }

    public function test_transfer_reference_cannot_be_reused_by_another_customer(): void
    {
        Storage::fake('local');
        $firstCustomer = User::query()->create(['name' => 'First Depositor', 'email' => 'first-depositor@example.test', 'password' => 'not-a-real-password']);
        $secondCustomer = User::query()->create(['name' => 'Second Depositor', 'email' => 'second-depositor@example.test', 'password' => 'not-a-real-password']);
        app(IntegrationSettings::class)->set('manual_deposit.enabled', '1', $firstCustomer->id);
        app(WalletAccountService::class)->openForUser($firstCustomer);
        app(WalletAccountService::class)->openForUser($secondCustomer);

        $this->actingAs($firstCustomer)->post('/wallet/manual-deposits', [
            'amount_minor' => 250000,
            'transfer_reference' => 'SHARED-BANK-REFERENCE-001',
            'evidence' => UploadedFile::fake()->image('first-receipt.png'),
        ])->assertRedirect('/wallet/manual-deposits');

        $this->actingAs($secondCustomer)->post('/wallet/manual-deposits', [
            'amount_minor' => 250000,
            'transfer_reference' => 'SHARED-BANK-REFERENCE-001',
            'evidence' => UploadedFile::fake()->image('second-receipt.png'),
        ])->assertSessionHasErrors('transfer_reference');

        $this->assertDatabaseCount('manual_deposits', 1);
        $this->assertDatabaseHas('manual_deposits', [
            'user_id' => $firstCustomer->id,
            'transfer_reference' => 'SHARED-BANK-REFERENCE-001',
            'status' => 'pending',
        ]);
        $this->assertSame(0, (int) $firstCustomer->wallets()->firstOrFail()->fresh()->balance_minor);
        $this->assertSame(0, (int) $secondCustomer->wallets()->firstOrFail()->fresh()->balance_minor);
    }

    public function test_only_staff_permission_managers_can_access_review_and_settings(): void
    {
        $user = User::query()->create(['name' => 'Customer', 'email' => 'only-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->get('/admin/manual-deposits')->assertForbidden();
        $this->actingAs($user)->get('/admin/manual-deposits/settings')->assertForbidden();
    }
}
