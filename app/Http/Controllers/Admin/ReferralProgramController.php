<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralProgramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use LogicException;

class ReferralProgramController extends Controller
{
    public function index(ReferralProgramService $referrals): InertiaResponse
    {
        return Inertia::render('Admin/ReferralProgram', $referrals->adminData());
    }

    public function update(Request $request, ReferralProgramService $referrals): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reward_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'qualifying_event' => ['required', 'in:admin_verified,first_verified_funding,first_successful_purchase'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $referrals->updateSettings(
                (bool) $data['enabled'],
                (int) $data['reward_minor'],
                $data['qualifying_event'],
                $request->user(),
                trim($data['reason']),
            );
        } catch (InvalidArgumentException $exception) {
            return back()->withErrors(['reward_minor' => $exception->getMessage()]);
        }

        return back()->with('success', 'Referral settings saved and audit logged.');
    }

    public function review(Request $request, int $referral, ReferralProgramService $referrals): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', 'in:qualify,reject'],
            'qualifying_reference' => ['nullable', 'string', 'max:120'],
            'review_note' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $referrals->review(
                $referral,
                $data['decision'],
                isset($data['qualifying_reference']) ? trim($data['qualifying_reference']) : null,
                trim($data['review_note']),
                $request->user(),
            );
        } catch (InvalidArgumentException|LogicException $exception) {
            return back()->withErrors(['review_note' => $exception->getMessage()]);
        }

        return back()->with('success', $data['decision'] === 'qualify'
            ? 'Referral reward posted to the referrer wallet and recorded.'
            : 'Referral marked rejected and recorded.');
    }
}
