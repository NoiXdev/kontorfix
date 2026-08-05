<?php

namespace App\Http\Middleware;

use App\Services\Setup\SetupStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the wizard routes. The wizard creates an admin account without any
 * authentication, so it must be sealed off the instant the instance has a user —
 * otherwise it would be a permanent unauthenticated admin-creation endpoint.
 */
class EnsureSetupIncomplete
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(SetupStatus::class)->isComplete()) {
            // Setup done — the wizard no longer exists as a destination; send visitors
            // to the app index, which itself routes to login or the dashboard.
            return redirect()->route('home');
        }

        return $next($request);
    }
}
