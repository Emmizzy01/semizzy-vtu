<?php

namespace App\Http\Middleware;

use App\Services\Security\UserSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TrackUserSession
{
    public function __construct(private readonly UserSessionService $sessions)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! $this->sessions->registerCurrent($request, $user)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['identifier' => 'This session has been revoked. Please sign in again.']);
        }

        return $next($request);
    }
}
