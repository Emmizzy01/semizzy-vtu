<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\ManualDeposit;
use App\Models\ManualDepositAudit;
use App\Models\Wallet;
use App\Services\IntegrationSettings;
use App\Services\Wallets\WalletAccountService;
use App\Services\Wallets\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'enabled' => (bool) $settings->get('manual_deposit.enabled', true),
            ],
            'walletCurrency' => $wallet?->currency ?? 'NGN',
            'deposits' => $deposits,
        ]);
    }

    public function submit(Request $request, WalletAccountService $wallets): RedirectResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:10000', 'max:500000000'],
            'transfer_reference' => ['required', 'string', 'min:4', 'max:120', 'regex:/^[A-Za-z0-9._\/-]+$/', 'unique:manual_deposits,transfer_reference'],
            'evidence' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ]);

        abort_unless((bool) app(IntegrationSettings::class)->get('manual_deposit.enabled', true), 403, 'Manual deposits are temporarily disabled.');
        $user = $request->user();
        abort_unless($user, 401);
        $wallet = $wallets->openForUser($user, 'NGN');
        $path = $request->file('evidence')->store('manual-deposits', 'local');
        if (! $path) {
            return back()->withErrors(['evidence' => 'Proof could not be stored securely. Please try again.']);
        }

        $deposit = ManualDeposit::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'amount_minor' => (int) $validated['amount_minor'],
            'transfer_reference' => trim($validated['transfer_reference']),
            'evidence_path' => $path,
            'status' => 'pending',
            'idempotency_key' => 'manual-deposit:'.Str::uuid(),
        ]);
        ManualDepositAudit::query()->create([
            'manual_deposit_id' => $deposit->id,
            'actor_user_id' => $user->id,
            'event_type' => 'submitted',
            'outcome' => 'pending',
            'context' => ['amount_minor' => $deposit->amount_minor, 'currency' => 'NGN'],
            'created_at' => now(),
        ]);

        return redirect()->route('wallet.manual-deposits.index')->with('status', 'Deposit submitted for verification. Your wallet has not been credited yet.');
    }

    public function adminIndex(Request $request): InertiaResponse
    {
        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'pending';
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
                'evidence_viewed' => $deposit->evidence_viewed_at !== null,
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

        DB::transaction(function () use ($publicId, $validated, $actor, $ledger): void {
            $deposit = ManualDeposit::query()->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            abort_unless($deposit->status === 'pending', 409, 'Only pending deposits can be reviewed.');
            abort_if((int) $deposit->user_id === (int) $actor->id, 403, 'You cannot review your own deposit.');
            abort_unless($deposit->evidence_viewed_at !== null, 422, 'Open and inspect the transfer evidence before reviewing.');

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

        return back()->with('status', 'Manual deposit review saved.');
    }
}
