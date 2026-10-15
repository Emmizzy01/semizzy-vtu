<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\TransactionPinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class TransactionPinController extends Controller
{
    public function show(Request $request): InertiaResponse
    {
        $user = $request->user();
        abort_unless($user, 401);
        return Inertia::render('Security/TransactionPin', [
            'configured' => (bool) $user->transaction_pin_hash,
            'lockedUntil' => $user->transaction_pin_locked_until?->toISOString(),
            'failedAttempts' => (int) $user->transaction_pin_failed_attempts,
        ]);
    }

    public function update(Request $request, TransactionPinService $pins): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user, 401);
        if ($user->transaction_pin_locked_until && $user->transaction_pin_locked_until->isFuture()) {
            return back()->withErrors(['current_pin' => 'PIN changes are locked until '.$user->transaction_pin_locked_until->toDayDateTimeString().'.']);
        }
        $wasConfigured = (bool) $user->transaction_pin_hash;
        $data = $request->validate([
            'current_pin' => $wasConfigured ? ['required', 'string', 'regex:/^\\d{4}(?:\\d{2})?$/'] : ['nullable', 'string', 'regex:/^\\d{4}(?:\\d{2})?$/'],
            'pin' => ['required', 'string', 'digits:6', 'confirmed', 'regex:/^(?!([0-9])\1{5})\d{6}$/'],
        ]);
        $pin = (string) $data['pin'];
        if (in_array($pin, ['0123', '1234', '2345', '3456', '4567', '5678', '6789', '9876', '8765', '7654', '6543', '5432', '4321', '3210', '1212', '1122', '2211', '1010'], true)) {
            return back()->withErrors(['pin' => 'Choose a less predictable four-digit PIN.']);
        }
        if ($wasConfigured) {
            $result = $pins->verify($user, (string) $data['current_pin']);
            if ($result !== 'verified') return back()->withErrors(['current_pin' => $result === 'locked' ? 'Too many incorrect PIN attempts. Try again after the lock expires.' : 'Current transaction PIN is incorrect.']);
            if (Hash::check($pin, (string) $user->transaction_pin_hash)) return back()->withErrors(['pin' => 'Choose a different PIN from your current one.']);
        }
        $user->forceFill([
            'transaction_pin_hash' => Hash::make($pin),
            'transaction_pin_failed_attempts' => 0,
            'transaction_pin_locked_until' => null,
            'transaction_pin_changed_at' => now(),
        ])->save();
        $pins->record($user->id, $wasConfigured ? 'pin_changed' : 'pin_created', 'success', []);
        return back()->with('status', 'Transaction PIN saved securely. Use it to authorize wallet-funded purchases.');
    }
}
