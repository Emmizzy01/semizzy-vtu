<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\VtuOrder;
use App\Services\Vtu\VtuOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class VtuOrderOperationsController extends Controller
{
    public function reconcile(string $publicId, VtuOrderService $service, Request $request): RedirectResponse
    {
        $order = VtuOrder::query()->where('public_id', $publicId)->firstOrFail();
        $before = $order->status;

        if (! in_array($before, ['pending', 'processing', 'awaiting_reconciliation'], true)
            || $order->dispatch_started_at === null) {
            return back()->with('status', 'This order is not eligible for provider reconciliation.');
        }

        $updated = $service->reconcile($order);

        Log::info('VTU order manually reconciled by authorised staff.', [
            'order_public_id' => $order->public_id,
            'actor_user_id' => $request->user()?->getKey(),
            'status_before' => $before,
            'status_after' => $updated->status,
        ]);

        return back()->with('status', $updated->status === $before
            ? 'Provider lookup completed; the order remains unresolved. No new purchase was submitted.'
            : 'Provider lookup updated the order to '.$updated->status.'.');
    }

    public function index(Request $request): InertiaResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:unresolved,all,pending,processing,awaiting_reconciliation,successful,failed,cancelled'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = trim((string) ($validated['search'] ?? ''));
        $status = (string) ($validated['status'] ?? 'unresolved');
        $query = VtuOrder::query()
            ->with('user:id,name,email')
            ->select([
                'id', 'public_id', 'user_id', 'service_type', 'network', 'recipient',
                'amount_minor', 'currency', 'status', 'provider', 'provider_reference',
                'failure_code', 'created_at', 'dispatch_started_at', 'completed_at',
            ]);

        if ($search !== '') {
            $query->where(function ($orders) use ($search): void {
                $term = '%' . $search . '%';
                $orders->where('public_id', 'like', $term)
                    ->orWhere('network', 'like', $term)
                    ->orWhere('service_type', 'like', $term)
                    ->orWhere('recipient', 'like', $term)
                    ->orWhere('provider_reference', 'like', $term)
                    ->orWhere('failure_code', 'like', $term)
                    ->orWhereHas('user', fn ($users) => $users
                        ->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term));
            });
        }

        if ($status === 'unresolved') {
            $query->whereIn('status', ['pending', 'processing', 'awaiting_reconciliation']);
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }

        $orders = $query->latest('id')->paginate(20)->withQueryString();

        return Inertia::render('Admin/VtuOrders/Index', [
            'orders' => $orders->getCollection()->map(fn (VtuOrder $order): array => [
                'id' => $order->public_id,
                'customer' => $order->user?->name ?? 'Deleted account',
                'customer_email' => $order->user?->email,
                'service_type' => $order->service_type,
                'network' => $order->network,
                'recipient' => $order->recipient,
                'amount_minor' => (int) $order->amount_minor,
                'currency' => $order->currency,
                'status' => $order->status,
                'provider' => $order->provider,
                'provider_reference' => $order->provider_reference,
                'failure_code' => $order->failure_code,
                'created_at' => $order->created_at?->toISOString(),
                'dispatch_started_at' => $order->dispatch_started_at?->toISOString(),
                'completed_at' => $order->completed_at?->toISOString(),
            ])->values(),
            'pagination' => [
                'currentPage' => $orders->currentPage(),
                'lastPage' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
            'filters' => ['search' => $search, 'status' => $status],
            'metrics' => [
                'unresolved' => VtuOrder::query()->whereIn('status', ['pending', 'processing', 'awaiting_reconciliation'])->count(),
                'awaitingReconciliation' => VtuOrder::query()->where('status', 'awaiting_reconciliation')->count(),
                'failed' => VtuOrder::query()->whereIn('status', ['failed', 'cancelled'])->count(),
            ],
        ]);
    }
}
