<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
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

        $request->attributes->set('portalOrganization', $organization);

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

        if (in_array($organization->id, $user->accessibleOrganizationIds(), true)) {
            return true;
        }

        // A super-admin first and on its own terms. Not merely a shortcut past the scan
        // below: that scan can only say yes if an `is_operator` row exists, and a super-admin
        // is not conditional on one — without this clause an instance with no operator
        // organization would 404 its own super-admin on every customer portal.
        if ($user->isSuperAdmin()) {
            return true;
        }

        // Otherwise: an operator account may look at any customer portal, and "operator
        // account" is wider than "super-admin". administeredOrganizationIds() returns every
        // organization the user is *admin or maintainer* of, by home role or by an
        // organization membership's pivot role — so an operator-org maintainer, and a
        // maintainer who only holds that role through a membership, may open any customer
        // portal too. That is deliberate (they administer the operator organization), but it
        // is not readable from the helper's name, so: admin or maintainer of the operator
        // organization, however that role is held, plus every super-admin.
        $administered = $user->administeredOrganizationIds();

        return Organization::query()->where('is_operator', true)->pluck('id')
            ->contains(fn (string $id): bool => in_array($id, $administered, true));
    }
}
