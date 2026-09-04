<?php

namespace App\Services\Portal;

use App\Models\Organization;
use Illuminate\Http\Request;

/**
 * The organization a portal URL addresses, as carried on the request.
 *
 * ResolvePortalContext resolves it from `/c/{orgSlug}` and puts it here; every surface
 * behind that middleware reads it back. Three controllers each had their own private
 * accessor spelling the attribute name out again, and the shared Inertia props are now a
 * fourth reader that is NOT a controller — so a controller trait or a portal base
 * controller could not hold all of them. This can, and it is the one place the attribute
 * name is written.
 *
 * Two readers because the two populations differ: behind `portal.context` the attribute is
 * guaranteed and its absence is a bug, while `share()` runs on every request in the
 * application and absence is the ordinary case there.
 */
final class PortalContext
{
    /** The request attribute the addressed organization travels on. */
    private const ATTRIBUTE = 'portalOrganization';

    public static function put(Request $request, Organization $organization): void
    {
        $request->attributes->set(self::ATTRIBUTE, $organization);
    }

    /** The addressed organization, or null on a request that addresses no portal. */
    public static function find(Request $request): ?Organization
    {
        $organization = $request->attributes->get(self::ATTRIBUTE);

        return $organization instanceof Organization ? $organization : null;
    }

    /**
     * The addressed organization on a route behind `portal.context`, where the middleware
     * has already resolved one or aborted.
     */
    public static function get(Request $request): Organization
    {
        $organization = self::find($request);

        // Not a soft answer: a portal action with no addressed organization would go on to
        // decide membership and scope tokens against nothing at all.
        abort_if($organization === null, 500, 'No portal organization on the request.');

        return $organization;
    }
}
