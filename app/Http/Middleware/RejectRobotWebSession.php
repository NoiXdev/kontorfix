<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RejectRobotWebSession
{
    /**
     * Defense-in-depth: robot accounts must not have an interactive web session.
     * Also catches login paths that bypass the auth controllers (e.g. the passkey vendor path).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->isRobot()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Robot-Accounts können sich nicht interaktiv anmelden.');
        }

        return $next($request);
    }
}
