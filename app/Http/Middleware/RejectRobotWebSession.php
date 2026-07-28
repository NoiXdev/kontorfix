<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RejectRobotWebSession
{
    /**
     * Defense-in-depth: Robot-Accounts dürfen keine interaktive Web-Session haben.
     * Fängt auch Login-Wege ab, die die Auth-Controller umgehen (z. B. Passkey-Vendorpfad).
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
