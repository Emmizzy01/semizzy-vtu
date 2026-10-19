<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Referrals\ReferralProgramService;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function index(Request $request, ReferralProgramService $referrals): Response
    {
        return Inertia::render('Account/Referrals', $referrals->dashboard($request->user()));
    }
}
