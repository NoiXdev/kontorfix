<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Instance-wide administration (organizations, users, robots, OIDC, storage, mail,
 * system settings, the global activity log and the shared package pool / webhooks) is
 * reserved for super-admins. Per-organization administration is gated separately.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->isSuperAdmin(), 403);

        return $next($request);
    }
}
