<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the self-service registration routes when an admin has turned registration
 * off (the default). Accounts are then created only by admins.
 */
class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SystemSetting::current()->registration_enabled) {
            // A guest landing on /register is sent to login; a POST is refused outright.
            if ($request->isMethod('GET')) {
                return redirect()->route('login')->with('status', 'Die Registrierung ist deaktiviert. Bitte wende dich an einen Administrator.');
            }

            abort(403, 'Registration is disabled.');
        }

        return $next($request);
    }
}
