<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\ManualDeposit;
use App\Models\ManualDepositAudit;
use App\Models\PlatformFeatureControl;
use App\Models\Wallet;
use App\Services\IntegrationSettings;
use App\Services\Payments\ManualDepositSubmissionService;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManualDepositController extends Controller
{
    public function index(Request $request, IntegrationSettings $settings): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user, 401);
        $wallet = Wallet::query()->where('user_id', $user->id)->where('currency', 'NGN')->first();

        $featureEnabled = PlatformFeatureControl::query()->where('feature_key', 'service.manual_deposit')->value('is_enabled');

        $deposits = ManualDeposit::query()->where('user_id', $user->id)->latest('id')->limit(30)->get([
            'public_id', 'amount_minor', 'transfer_reference', 'status', 'created_at', 'reviewed_at', 'review_reason',
        ])->map(fn (ManualDeposit $deposit): array => [
            'id' => $deposit->public_id,
            'amount_minor' => $deposit->amount_minor,
            'reference' => $deposit->transfer_reference,
            'status' => $deposit->status,
            'created_at' => $deposit->created_at?->toISOString(),
            'reviewed_at' => $deposit->reviewed_at?->toISOString(),
            'review_reason' => $deposit->review_reason,
        ]);

        return Inertia::render('Wallet/ManualDeposit', [
            'instructions' => [
                'bank_name' => (string) $settings->get('manual_deposit.bank_name', ''),
                'account_name' => (string) $settings->get('manual_deposit.account_name', ''),
                'account_number' => (string) $settings->get('manual_deposit.account_number', ''),
                'instructions' => (string) $settings->get('manual_deposit.instructions', 'Transfer the exact amount, then submit your transfer reference and proof. Your wallet is credited only after staff verify the payment.'),
                'enabled' => filter_var($settings->get('manual_deposit.enabled', false), FILTER_VALIDATE_BOOLEAN)
                    && $featureEnabled !== null && (bool) $featureEnabled,
            ],
            'walletCurrency' => $wallet?->currency ?? 'NGN',
            'deposits' => $deposits,
        ]);
    }

    public function submit(Request $request, WalletAccountService $wallets, ManualDepositSubmissionService $submissions): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:10000', 'max:500000000'],
            'transfer_reference' => ['required', 'string', 'min:4', 'max:120', 'regex:/^[A-Za-z0-9._\/-]+$/', 'unique:manual_deposits,transfer_reference'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        $featureEnabled = PlatformFeatureControl::query()->where('feature_key', 'service.manual_deposit')->value('is_enabled');
        abort_unless(
            filter_var(app(IntegrationSettings::class)->get('manual_deposit.enabled', false), FILTER_VALIDATE_BOOLEAN)
                && $featureEnabled !== null && (bool) $featureEnabled,
            403,
            'Manual deposits are temporarily disabled.',
        );
        $user = $request->user();
        abort_unless($user, 401);
        $wallet = $wallets->openForUser($user, 'NGN');
        $evidenceHash = hash_file('sha256', $request->file('evidence')->getRealPath());
        $path = $request->file('evidence')->store('manual-deposits', 'local');
        if (! $path) {
            return back()->withErrors(['evidence' => 'Proof could not be stored securely. Please try again.']);
        }

        $deposit = $submissions->submit(
            $user,
            $wallet,
            (int) $validated['amount_minor'],
            trim($validated['transfer_reference']),
            $path,
            $evidenceHash,
        );
        $duplicateEvidence = (bool) $deposit->duplicate_evidence;
        return redirect()->route('wallet.manual-deposits.index')->with('status', $duplicateEvidence ? 'Deposit submitted but flagged because this receipt matches earlier evidence. Wallet credit is blocked pending investigation.' : 'Deposit submitted for verification. Your wallet has not been credited yet.');
    }

    public function adminIndex(Request $request): InertiaResponse
    {
        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'review_required', 'approved', 'rejected', 'all'], true)) $status = 'pending';
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);
        $query = ManualDeposit::query()->with(['user:id,name,email'])->select([
            'id', 'public_id', 'user_id', 'wallet_id', 'amount_minor', 'transfer_reference', 'status',
            'evidence_path', 'evidence_viewed_at', 'evidence_viewed_by_user_id', 'reviewed_by_user_id',
            'reviewed_at', 'review_reason', 'credited_wallet_transaction_id', 'created_at',
        ]);
        if ($status !== 'all') $query->where('status', $status);
        if ($search !== '') {
            $query->where(function ($builder) use ($search): void {
                $builder->where('public_id', 'like', '%'.$search.'%')
                    ->orWhere('transfer_reference', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn ($userQuery) => $userQuery->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
            });
        }
        $page = $query->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Admin/ManualDeposits/Index', [
            'deposits' => $page->through(fn (ManualDeposit $deposit): array => [
                'id' => $deposit->public_id, 'customer' => $deposit->user?->name,
                'email' => $deposit->user?->email, 'amount_minor' => $deposit->amount_minor,
                'reference' => $deposit->transfer_reference, 'status' => $deposit->status,
                'created_at' => $deposit->created_at?->toISOString(),
                'duplicate_evidence' => (bool) $deposit->duplicate_evidence,
                'evidence_viewed_by_current_admin' => (int) $deposit->evidence_viewed_by_user_id === (int) $request->user()->id,
                'review_reason' => $deposit->review_reason,
            ]),
            'filters' => ['status' => $status, 'search' => $search],
        ]);
    }

    public function evidence(Request $request, string $publicId): StreamedResponse
    {
        $deposit = ManualDeposit::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($deposit->evidence_path), 404);
        $deposit->forceFill(['evidence_viewed_at' => now(), 'evidence_viewed_by_user_id' => $request->user()->id])->save();
        ManualDepositAudit::query()->create([
            'manual_deposit_id' => $deposit->id, 'actor_user_id' => $request->user()->id,
            'event_type' => 'evidence_viewed', 'outcome' => 'success', 'created_at' => now(),
        ]);
        return Storage::disk('local')->download($deposit->evidence_path, 'deposit-evidence-'.$deposit->public_id);
    }

    public function review(Request $request, string $publicId, WalletLedgerService $ledger): RedirectResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $actor = $request->user();
        abort_unless($actor, 401);

        try {
            DB::transaction(function () use ($publicId, $validated, $actor, $ledger): void {
            $deposit = ManualDeposit::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($deposit->status, ['pending', 'review_required'], true), 409, 'Only pending or reconciliation-required deposits can be reviewed.');
            abort_if((int) $deposit->user_id === (int) $actor->id, 403, 'You cannot review your own deposit.');
            abort_unless((int) $deposit->evidence_viewed_by_user_id === (int) $actor->id, 422, 'Open and inspect the transfer evidence yourself before reviewing.');
            abort_if($deposit->duplicate_evidence && $validated['decision'] === 'approve', 422, 'Duplicate receipt evidence cannot be approved here; investigate and reject or escalate it.');

            if ($validated['decision'] === 'approve') {
                $transaction = $ledger->post(
                    walletId: (int) $deposit->wallet_id,
                    type: 'credit',
                    amountMinor: (int) $deposit->amount_minor,
                    idempotencyKey: $deposit->idempotency_key,
                    reference: $deposit->public_id,
                    description: 'Verified manual bank deposit',
                    actorUserId: (int) $actor->id,
                    metadata: ['manual_deposit_id' => $deposit->public_id, 'transfer_reference' => $deposit->transfer_reference],
                );
                $deposit->forceFill([
                    'status' => 'approved', 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                    'review_reason' => trim($validated['reason']), 'credited_wallet_transaction_id' => $transaction->id,
                ])->save();
            } else {
                $deposit->forceFill([
                    'status' => 'rejected', 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                    'review_reason' => trim($validated['reason']),
                ])->save();
            }

            ManualDepositAudit::query()->create([
                'manual_deposit_id' => $deposit->id, 'actor_user_id' => $actor->id,
                'event_type' => $validated['decision'], 'outcome' => $deposit->status,
                'reason' => trim($validated['reason']),
                'context' => ['amount_minor' => $deposit->amount_minor, 'currency' => 'NGN'],
                'created_at' => now(),
            ]);
            }, attempts: 3);
        } catch (DomainException $exception) {
            if (! str_contains($exception->getMessage(), 'account level would be exceeded')) {
                throw $exception;
            }

            // Keep the submitted deposit and audit trail visible for reconciliation.
            DB::transaction(function () use ($publicId, $actor, $validated): void {
                $deposit = ManualDeposit::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
                if ($deposit->status !== 'pending') {
                    return;
                }
                $deposit->forceFill([
                    'status' => 'review_required',
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'review_reason' => 'Wallet level balance cap reached. '.trim($validated['reason']),
                ])->save();
                ManualDepositAudit::query()->create([
                    'manual_deposit_id' => $deposit->id,
                    'actor_user_id' => $actor->id,
                    'event_type' => 'wallet_limit_review',
                    'outcome' => 'review_required',
                    'reason' => trim($validated['reason']),
                    'context' => ['amount_minor' => $deposit->amount_minor, 'currency' => 'NGN'],
                    'created_at' => now(),
                ]);
            }, attempts: 3);

            return back()->withErrors(['amount' => 'This wallet reached its account-level balance limit. The deposit was preserved for reconciliation and was not credited.']);
        }

        return back()->with('status', 'Manual deposit review saved.');
    }
}
