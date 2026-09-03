<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Organization;
use App\Services\Registry\LegacySlugRedirector;
use App\Services\Registry\RegistryUrl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveRegistryContext
{
    public function __construct(
        private readonly LegacySlugRedirector $legacy,
        private readonly RegistryUrl $urls,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $orgSlug = $request->route('orgSlug');
        $slug = $request->route('groupSlug');

        if ($slug !== null) {
            // Slug access: /r/{orgSlug}/{groupSlug}/... A slug is unique only within its
            // organization, so both segments are part of the lookup.
            //
            // Resolved as two seeks rather than one query carrying the organization as a
            // correlated EXISTS. `groups` carries a composite index on (organization_id,
            // slug) for exactly this lookup, plus a separate index on `slug` alone for the
            // legacy fallback below — and Postgres cannot seek the composite one on its
            // second column: filtering by slug first against it is a sequential scan over
            // every registry on the instance, on every metadata request, and one
            // `composer install` fires hundreds. Measured on 400 registries:
            // "Seq Scan on groups … Rows Removed by Filter: 200" against an "Index Scan
            // using groups_organization_id_slug_unique … (organization_id = … AND slug = …)"
            // for the shape below.
            $organization = Organization::where('slug', $orgSlug)->first();

            if ($organization === null) {
                // Not necessarily an unknown URL: the router already committed to this
                // canonical two-segment route before this middleware runs, and a legacy
                // one-segment URL can still land here when its own remaining path happens
                // to satisfy some endpoint's pattern one segment later than usual. pip is
                // the concrete case — GET /r/{slug}/simple/{project} reads as
                // {orgSlug}={slug}, {groupSlug}="simple", then npm's bare {package} pattern
                // for {project} — a real route match, so LegacySlugRedirectController's own
                // route (registered after this one) never gets tried at all. $orgSlug is
                // then not an organization slug but a legacy registry slug; hand it to the
                // same resolver LegacySlugRedirectController uses. That resolver matches the
                // frozen `legacy_slug` column rather than the live `slug`, so a slug shared by
                // two organizations still resolves unambiguously here too, to whichever
                // registry held it when the instance was upgraded (see LegacySlugRedirector).
                $legacyGroup = $this->legacy->resolve($orgSlug);
                abort_if($legacyGroup === null, 404);

                return $this->legacy->respond($this->legacy->target($legacyGroup, $request, $this->urls, 2), $request);
            }

            $group = $organization->groups()->where('slug', $slug)->first();
            abort_if($group === null, 404);

            // The relation is genuinely this object — the group came out of its own
            // hasMany — and saying so keeps RegistryUrl::path() from re-fetching a row the
            // resolution already held. path() feeds the base URL and the metadata-url
            // prefix of every response, so that lazy load would otherwise recur per request.
            $group->setRelation('organization', $organization);

            $request->attributes->set('registryGroup', $group);
            $request->attributes->set('registryDomainMode', false);

            // Controller actions know neither parameter. Without this, Laravel's purely
            // positional controller dispatch would shift every later route parameter.
            $request->route()->forgetParameter('orgSlug');
            $request->route()->forgetParameter('groupSlug');
        } else {
            // Domain access: registry at the host root. Unknown host -> 404
            // (protects the main app: foreign hosts fall through cleanly).
            $domain = Domain::where('hostname', $request->getHost())->first();
            abort_if($domain === null, 404);
            $request->attributes->set('registryGroup', $domain->group);
            $request->attributes->set('registryDomainMode', true);
        }

        return $next($request);
    }
}
