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

        // The wizard page *is* the token prompt (it renders nothing but the token field
        // while locked), and the unlock endpoint is what that field submits to — so both
        // have to stay reachable while locked, or the gate could never be satisfied.
        if ($request->routeIs('setup.show', 'setup.unlock')) {
            return $next($request);
        }

        abort(403, 'Setup token required.');
    }
}
