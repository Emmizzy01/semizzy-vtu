<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPermission;
use App\Models\ManualDeposit;
use App\Models\Wallet;
use App\Services\Wallets\WalletAccountService;
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

        $this->actingAs($customer)->post('/wallet/manual-deposits', [
            'amount_minor' => 250000,
            'transfer_reference' => 'BANK-TRANSFER-0001',
            'evidence' => UploadedFile::fake()->image('receipt.png'),
        ])->assertRedirect('/wallet/manual-deposits');

        $this->assertSame(0, (int) $wallet->fresh()->balance_minor);
        $this->assertDatabaseHas('manual_deposits', ['user_id' => $customer->id, 'status' => 'pending', 'transfer_reference' => 'BANK-TRANSFER-0001']);
    }


    public function test_duplicate_receipt_evidence_is_flagged_and_cannot_be_approved(): void
    {
        Storage::fake('local');
        $customer = User::query()->create(['name' => 'Duplicate Receipt Customer', 'email' => 'duplicate-receipt@example.test', 'password' => 'not-a-real-password']);
        $receiptContent = '%PDF-1.4\nSemizzy duplicate receipt test\n%%EOF';
        $wallet = app(WalletAccountService::class)->openForUser($customer);

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

        $deposit = ManualDeposit::query()->where('transfer_reference', 'DUPLICATE-RECEIPT-0002')->firstOrFail();
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

    public function test_only_staff_permission_managers_can_access_review_and_settings(): void
    {
        $user = User::query()->create(['name' => 'Customer', 'email' => 'only-customer@example.test', 'password' => 'not-a-real-password']);
        $this->actingAs($user)->get('/admin/manual-deposits')->assertForbidden();
        $this->actingAs($user)->get('/admin/manual-deposits/settings')->assertForbidden();
    }
}
