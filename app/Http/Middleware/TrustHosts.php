<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Base;
use Illuminate\Http\Request;

/**
 * Keeps the health endpoint answerable under whatever name a checker uses.
 *
 * The allowlist exists so a `Host` header cannot choose where a generated link points —
 * see App\Services\Http\TrustedHosts. It never accounted for a second kind of caller: an
 * orchestrator that health-checks a backend by its container IP. Traefik does exactly
 * that, got a 400, marked the only server down, and every request got 503 while Docker's
 * own healthcheck — which uses the allowlisted 127.0.0.1 — reported the container healthy.
 *
 * The list is CLEARED rather than merely left unset, because `setTrustedHosts()` is static
 * and process-global: a list installed by an earlier request in the same worker would
 * otherwise still be in force when the health check arrives.
 *
 * Safe for this route and no other: `/up` returns a constant and never reads the host, and
 * `$request->path()` does not read it either.
 *
 * `$next` is intentionally untyped: the parent declares it without a type, and a
 * `Closure`/`Response` signature here is a narrower, incompatible override — PHP raises a
 * fatal "must be compatible" error at class-load time rather than a lint warning.
 */
class TrustHosts extends Base
{
    public function handle(Request $request, $next)
    {
        if ($request->path() === 'up') {
            Request::setTrustedHosts([]);

            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
