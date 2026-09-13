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
        } elseif ($orgSlug !== null) {
            // Org access: /o/{orgSlug}/... — no {groupSlug} segment at all, unlike slug
            // access above. This prefix lists every registry the organization owns rather
            // than resolving one, so there is no group to set here: `registryGroup` stays
            // null on purpose, which is also what keeps EnsureRegistryTypeEnabled's existing
            // "no group -> pass through" branch behaving the same way it already documents
            // for the OCI handshake case. Task 3+ wires the org-level controllers to read
            // `registryOrganization` directly instead.
            // An unknown slug does NOT 404 here. A 404 for an unknown organization beside
            // the 401 a real one answers with made this single path segment an oracle for
            // the first thing in the URL — which, since organizations got their own slug,
            // is the CUSTOMER's name. Anonymous, un-throttled, one segment, wordlist-sized:
            // "is this company a customer of this instance". ResolvePortalContext's own
            // docblock names precisely that as something the portal is careful not to
            // answer, so this was an internal contradiction rather than a protocol
            // necessity. The 401 for a real organization is not the bug and stays — a
            // reactive client (Composer's prompt, pip's keyring lookup, twine) needs it.
            //
            // Instead the unknown slug resolves to an organization that exists only for
            // this request and that nothing can hold a token for: it has no id, so
            // canAccessOrganization()'s `token->organization_id === org->id` is false for
            // every token, and the ordinary refusal in authorizeOrganization() runs — 401
            // without credentials, 403 with a token that does not grant access. Both are
            // byte-identical to what a real organization the caller may not read answers,
            // which is the property, rather than a second hand-written refusal that could
            // drift away from the first.
            $organization = Organization::where('slug', $orgSlug)->first()
                ?? tap(new Organization, fn (Organization $o) => $o->slug = $orgSlug);

            $request->attributes->set('registryOrganization', $organization);
            $request->attributes->set('registryGroup', null);
            $request->attributes->set('registryDomainMode', false);

            $request->route()->forgetParameter('orgSlug');
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
