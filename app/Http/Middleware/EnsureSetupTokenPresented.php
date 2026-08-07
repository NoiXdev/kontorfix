<?php

namespace App\Http\Middleware;

use App\Enums\SetupGateState;
use App\Services\Setup\SetupGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route-level gate for the first-run wizard: default-deny for the whole setup group.
 *
 * Sitting on the group rather than inside the controller is the point — a wizard route
 * added later is gated by construction instead of by remembering to call a helper. The
 * one carve-out is the wizard page itself, which *is* the token prompt and therefore
 * has to stay reachable while locked; it renders nothing but the token field.
 */
class EnsureSetupTokenPresented
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(SetupGate::class)->state($request) !== SetupGateState::Locked) {
            return $next($request);
        }

        if ($request->routeIs('setup.show')) {
            return $next($request);
        }

        abort(403, 'Setup token required.');
    }
}
