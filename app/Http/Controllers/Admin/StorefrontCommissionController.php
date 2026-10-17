<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessWorkspaceCommission;
use App\Models\BusinessWorkspaceStorefront;
use App\Services\Workspaces\StorefrontCommissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StorefrontCommissionController extends Controller
{
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
