<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Security\UserSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class UserSessionController extends Controller
{
    public function index(Request $request, UserSessionService $sessions): InertiaResponse
    {
        $user = $request->user();
        $currentHash = $sessions->currentHash($request);
        $items = DB::table('user_sessions')->where('user_id', $user->id)->whereNull('revoked_at')
            ->orderByDesc('last_activity')->get()->map(fn ($session) => [
                'id' => $session->id, 'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent ?: 'Unknown browser or device',
                'last_activity' => $session->last_activity,
                'is_current' => hash_equals($session->session_hash, $currentHash),
            ]);
        return Inertia::render('Account/Sessions', ['sessions' => $items]);
    }

    public function revoke(Request $request, string $sessionId, UserSessionService $sessions): RedirectResponse
    {
        $session = DB::table('user_sessions')->where('id', $sessionId)->where('user_id', $request->user()->id)
            ->whereNull('revoked_at')->first();
        if (! $session) {
            return back()->withErrors(['session' => 'That session is no longer active.']);
        }
        $sessions->revoke($request->user(), $sessionId);
        if (hash_equals($session->session_hash, $sessions->currentHash($request))) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->with('status', 'This device has been signed out.');
        }
        return back()->with('status', 'The selected session has been revoked.');
    }

    public function revokeOthers(Request $request, UserSessionService $sessions): RedirectResponse
    {
        $count = $sessions->revokeOthers($request->user(), $sessions->currentHash($request));
        return back()->with('status', $count.' other session(s) have been revoked.');
    }
}
