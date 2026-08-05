<?php

namespace App\Http\Middleware;

use App\Services\Setup\SetupStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the instance has no user account, the setup wizard is the ONLY reachable part
 * of the web UI.
 *
 * This deliberately covers writing routes too, not just the pages: leaving POST
 * /register open would let anyone create the first account as a plain member with no
 * organization — which both hands out the instance to a stranger and permanently
 * locks the wizard (it only runs while zero users exist), leaving nobody with admin.
 */
class RequireSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app(SetupStatus::class)->needsSetup()) {
            return $next($request);
        }

        // Don't bounce the wizard itself, or the health check used by the container.
        if ($request->routeIs('setup.*') || $request->is('up')) {
            return $next($request);
        }

        // Inertia performs the redirect client-side; a plain 302 to a non-Inertia
        // page would otherwise be swallowed by the XHR.
        return redirect()->route('setup.show');
    }
}
