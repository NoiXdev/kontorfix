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

        // An operator account may look at any customer portal. "Operator account" means a
        // user who administers the operator organization: administeredOrganizationIds()
        // returns every organization for a super-admin, and isSuperAdmin() in turn
        // grandfathers an Admin whose home organization is the operator organization.
        $administered = $user->administeredOrganizationIds();

        return Organization::query()->where('is_operator', true)->pluck('id')
            ->contains(fn (string $id): bool => in_array($id, $administered, true));
    }
}
