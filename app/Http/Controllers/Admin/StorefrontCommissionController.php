<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessWorkspaceCommission;
use App\Models\BusinessWorkspaceStorefront;
use App\Services\Workspaces\StorefrontCommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StorefrontCommissionController extends Controller
{
    public function index(): Response
    {
        $storefronts = BusinessWorkspaceStorefront::query()
            ->with(['workspace:id,name,owner_user_id', 'workspace.owner:id,name,email'])
            ->withCount(['commissions as accrued_commissions_count' => fn ($query) => $query->where('status', 'accrued')])
            ->withSum(['commissions as accrued_commissions_minor' => fn ($query) => $query->where('status', 'accrued')], 'commission_amount_minor')
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->through(fn (BusinessWorkspaceStorefront $storefront): array => [
                'id' => $storefront->id,
                'slug' => $storefront->slug,
                'display_name' => $storefront->display_name,
                'status' => $storefront->status,
                'commission_rate_basis_points' => (int) $storefront->commission_rate_basis_points,
                'workspace_name' => $storefront->workspace?->name,
                'owner_name' => $storefront->workspace?->owner?->name,
                'owner_email' => $storefront->workspace?->owner?->email,
                'accrued_commissions_count' => (int) ($storefront->accrued_commissions_count ?? 0),
                'accrued_commissions_minor' => (int) ($storefront->accrued_commissions_minor ?? 0),
            ]);

        $commissions = BusinessWorkspaceCommission::query()
            ->with(['storefront:id,display_name,slug', 'beneficiary:id,name,email', 'order:id,public_id,status'])
            ->orderByRaw("CASE WHEN status = 'accrued' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(30, ['*'], 'commissions_page')
            ->through(fn (BusinessWorkspaceCommission $commission): array => [
                'id' => $commission->id,
                'storefront_name' => $commission->storefront?->display_name,
                'storefront_slug' => $commission->storefront?->slug,
                'beneficiary_name' => $commission->beneficiary?->name,
                'beneficiary_email' => $commission->beneficiary?->email,
                'order_public_id' => $commission->order?->public_id,
                'order_status' => $commission->order?->status,
                'gross_amount_minor' => (int) $commission->gross_amount_minor,
                'rate_basis_points' => (int) $commission->rate_basis_points,
                'commission_amount_minor' => (int) $commission->commission_amount_minor,
                'status' => $commission->status,
                'settled_at' => $commission->settled_at?->toISOString(),
                'created_at' => $commission->created_at?->toISOString(),
            ]);

        return Inertia::render('Admin/StorefrontCommissions/Index', [
            'storefronts' => $storefronts,
            'commissions' => $commissions,
        ]);
    }

    public function updateRate(Request $request, BusinessWorkspaceStorefront $storefront): RedirectResponse
    {
        $validated = $request->validate([
            'commission_rate_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        $storefront->forceFill([
            'commission_rate_basis_points' => (int) $validated['commission_rate_basis_points'],
        ])->save();

        return back()->with('success', 'Storefront commission rate updated.');
    }

    public function settle(Request $request, BusinessWorkspaceCommission $commission, StorefrontCommissionService $commissions): RedirectResponse
    {
        try {
            $commissions->settle((int) $commission->getKey(), $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['commission' => $exception->getMessage()]);
        }

        return back()->with('success', 'Commission settlement posted to the reseller wallet.');
    }
}
