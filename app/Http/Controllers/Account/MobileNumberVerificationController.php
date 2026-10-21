<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Security\MobileNumberVerificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MobileNumberVerificationController extends Controller
{
    public function send(Request $request, MobileNumberVerificationService $verification): RedirectResponse
    {
        try {
            $verification->start($request->user());
        } catch (\Throwable $exception) {
            report($exception);
            return back()->withErrors(['mobile_number' => 'We could not send a verification code. Confirm your number and try again later.']);
        }

        return back()->with('status', 'mobile-verification-sent');
    }

    public function confirm(Request $request, MobileNumberVerificationService $verification): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $result = $verification->verify($request->user(), (string) $data['code']);

        if ($result === 'verified') {
            return back()->with('status', 'mobile-number-verified');
        }

        if ($result === 'duplicate') {
            return back()->withErrors(['mobile_number' => 'This mobile number is already verified on another account.']);
        }

        $message = match ($result) {
            'expired', 'missing' => 'The verification code expired or is no longer active. Request a new code.',
            'locked' => 'Too many incorrect verification codes. Request a new code and try again.',
            default => 'The verification code is incorrect.',
        };

        return back()->withErrors(['verification_code' => $message]);
    }
}
