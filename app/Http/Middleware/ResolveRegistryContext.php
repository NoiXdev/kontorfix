<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveRegistryContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $orgSlug = $request->route('orgSlug');
        $slug = $request->route('groupSlug');

        if ($slug !== null) {
            // Slug access: /r/{orgSlug}/{groupSlug}/... A slug is unique only within its
            // organization, so both segments are part of the lookup.
            //
            // Resolved as two seeks rather than one query carrying the organization as a
            // correlated EXISTS. The only index on `groups` is (organization_id, slug), and
            // Postgres cannot seek a composite index on its second column: filtering by
            // slug first is a sequential scan over every registry on the instance, on every
            // metadata request, and one `composer install` fires hundreds. Measured on 400
            // registries: "Seq Scan on groups … Rows Removed by Filter: 200" against an
            // "Index Scan using groups_organization_id_slug_unique … (organization_id = …
            // AND slug = …)" for the shape below. An unknown organization answers 404
            // exactly as an unknown registry does — the client is told no more than that
            // this URL names nothing.
            $organization = Organization::where('slug', $orgSlug)->first();
            abort_if($organization === null, 404);

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
