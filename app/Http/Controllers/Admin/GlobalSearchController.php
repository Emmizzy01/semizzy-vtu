<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ManualDeposit;
use App\Models\PaymentIntent;
use App\Models\User;
use App\Models\VtuOrder;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request): InertiaResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = trim($data['q']);
        $like = '%'.addcslashes($term, '\\%_').'%';
        $encoded = rawurlencode($term);

        $users = User::query()->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->latest('id')->limit(5)->get(['id', 'name', 'email', 'user_type', 'user_level', 'kyc_status', 'created_at'])
            ->map(fn (User $user): array => ['title' => $user->name, 'subtitle' => $user->email.' · '.($user->user_type ?: 'customer'), 'status' => $user->kyc_status ?: 'unverified', 'href' => '/admin/users?search='.$encoded, 'created_at' => $user->created_at?->toISOString()]);

        $orders = VtuOrder::query()->with('user:id,name,email')
            ->where(fn ($q) => $q->where('public_id', 'like', $like)->orWhere('network', 'like', $like)->orWhere('service_type', 'like', $like)->orWhere('recipient', 'like', $like)->orWhere('provider_reference', 'like', $like)->orWhere('failure_code', 'like', $like)->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->latest('id')->limit(5)->get(['id', 'public_id', 'user_id', 'service_type', 'network', 'recipient', 'amount_minor', 'currency', 'status', 'provider_reference', 'failure_code', 'created_at'])
            ->map(fn (VtuOrder $order): array => ['title' => $order->public_id, 'subtitle' => ($order->user?->name ?? 'Unknown customer').' · '.$order->network.' '.$order->service_type.' · '.$order->recipient, 'status' => $order->status, 'href' => '/admin/vtu-orders?search='.$encoded, 'amount_minor' => (int) $order->amount_minor, 'currency' => $order->currency, 'created_at' => $order->created_at?->toISOString()]);

        $deposits = ManualDeposit::query()->with('user:id,name,email')
            ->where(fn ($q) => $q->where('public_id', 'like', $like)->orWhere('transfer_reference', 'like', $like)->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->latest('id')->limit(5)->get(['id', 'public_id', 'user_id', 'amount_minor', 'transfer_reference', 'status', 'created_at'])
            ->map(fn (ManualDeposit $deposit): array => ['title' => $deposit->public_id, 'subtitle' => ($deposit->user?->name ?? 'Unknown customer').' · '.$deposit->transfer_reference, 'status' => $deposit->status, 'href' => '/admin/manual-deposits?search='.$encoded, 'amount_minor' => (int) $deposit->amount_minor, 'currency' => 'NGN', 'created_at' => $deposit->created_at?->toISOString()]);

        $transactions = WalletTransaction::query()->with('wallet.user:id,name,email')
            ->where(fn ($q) => $q->where('public_id', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('description', 'like', $like)->orWhere('type', 'like', $like)->orWhere('status', 'like', $like)->orWhereHas('wallet.user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->latest('id')->limit(5)->get(['id', 'public_id', 'wallet_id', 'type', 'status', 'currency', 'amount_minor', 'reference', 'description', 'created_at'])
            ->map(fn (WalletTransaction $transaction): array => ['title' => $transaction->reference ?: $transaction->public_id, 'subtitle' => ($transaction->wallet?->user?->name ?? 'Unknown customer').' · '.($transaction->description ?: $transaction->type), 'status' => $transaction->status, 'amount_minor' => (int) $transaction->amount_minor, 'currency' => $transaction->currency, 'created_at' => $transaction->created_at?->toISOString()]);

        $funding = PaymentIntent::query()->with('wallet.user:id,name,email')
            ->where(fn ($q) => $q->where('public_id', 'like', $like)->orWhere('reference', 'like', $like)->orWhere('status', 'like', $like)->orWhereHas('wallet.user', fn ($u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)))
            ->latest('id')->limit(5)->get(['id', 'public_id', 'wallet_id', 'reference', 'amount_minor', 'currency', 'status', 'created_at'])
            ->map(fn (PaymentIntent $intent): array => ['title' => $intent->reference, 'subtitle' => ($intent->wallet?->user?->name ?? 'Unknown customer').' · '.$intent->public_id, 'status' => $intent->status, 'amount_minor' => (int) $intent->amount_minor, 'currency' => $intent->currency, 'created_at' => $intent->created_at?->toISOString()]);

        return Inertia::render('Admin/GlobalSearch', [
            'query' => $term,
            'results' => ['users' => $users, 'orders' => $orders, 'manualDeposits' => $deposits, 'walletTransactions' => $transactions, 'funding' => $funding],
        ]);
    }
}
