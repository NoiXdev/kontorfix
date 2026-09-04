<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Services\Portal\PortalContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePortalContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = Organization::where('slug', $request->route('orgSlug'))->first();

        // All three failures answer 404, never 403, and that uniformity is the point: a 403
        // on /c/some-customer confirms that customer exists. The registry paths and
        // findAccessible() already answer this way, and the portal is exactly the surface on
        // which customer slugs would otherwise be guessed one response code at a time.
        abort_if($organization === null, 404);
        abort_unless($organization->portal_enabled, 404);
        abort_unless($this->mayOpen($request->user(), $organization), 404);

        PortalContext::put($request, $organization);

        // Controller actions do not take {orgSlug}. Without this, Laravel's positional
        // controller dispatch shifts every later route parameter — the same reason
        // ResolveRegistryContext forgets its own two.
        $request->route()->forgetParameter('orgSlug');

        return $next($request);
    }

    private function mayOpen(?User $user, Organization $organization): bool
    {
        if ($user === null) {
            return false;
        }

        // belongsToOrganization(), the ONE statement of the membership question — the same
        // one HandleInertiaRequests::portal() and TokenController::store() ask, and the one
        // RegistryTokenPolicy and GroupPolicy already asked. This was the third inline
        // spelling of it, and the gate is where the portal's whole membership invariant
        // starts, so leaving it inline made the invariant rest on comments elsewhere
        // pointing at it.
        if ($user->belongsToOrganization($organization->id)) {
            return true;
        }

        // Otherwise: an operator account may look at any customer portal. The rule lives on
        // User because GroupPolicy::view() has to answer it identically — it used to ask a
        // narrower version, which let these accounts through this gate and then refused them
        // every registry page behind it.
        return $user->administersOperatorOrganization();
    }
}
