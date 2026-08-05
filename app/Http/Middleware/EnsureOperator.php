<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the admin console. Under the per-organization role model this means: a
 * super-admin, or anyone who is admin/maintainer of at least one organization. The
 * individual controllers additionally scope every query to the organizations the user
 * may actually administer, so a customer-org admin only ever sees their own data.
 */
class EnsureOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) $request->user()?->canAdministerConsole(), 403);

        return $next($request);
    }
}
