<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The registry an OCI request addresses, resolved from the `Host` — the ONE place both
 * addressing modes are decided.
 *
 * A registry is reachable two ways: at the root of a hostname the operator attached to it
 * (`images.example.com/v2/meinapp`), and by path namespace on the instance's own host
 * (`registry.example.com/v2/3b/intern/meinapp`). What follows the host in an image
 * reference is not part of the registry address — it is the repository NAME, and the OCI
 * grammar allows slashes in it — so the second form is an ordinary reference whose
 * repository name happens to be `3b/intern/meinapp`.
 *
 * Why one resolver and not a second route group. Middleware runs AFTER route matching, and
 * a path-mode URL is a valid domain-mode URL whose `{name}` contains slashes: the two
 * patterns are indistinguishable to the router, so whichever group registered first would
 * match on EVERY host and the second would be dead code — and a losing group's middleware
 * cannot hand the request back to the router. routes/registry.php's own header records the
 * same trap being sprung once already, when npm's `{package}` catch-all swallowed `/v2/*`.
 * So the `/v2/{name}/…` routes stay exactly as they are and this decides what `{name}`
 * means, rewriting the route parameter to the bare repository name. Every controller reads
 * `registryGroup` and `{name}` exactly as it did before path addressing existed.
 *
 * Authorization is untouched by any of this: both modes resolve to the same `Group`, and
 * `canAccessGroup()`, `canPublishToGroup()`, `packageBelongsToGroup()` and the blob gate
 * all run on it unchanged. A path-mode address is a second door to one room.
 */
class ResolveOciContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = Domain::where('hostname', $request->getHost())->first();

        if ($domain !== null) {
            // Domain mode, unchanged: the group comes from the hostname and `{name}` is the
            // whole repository name, slashes and all.
            $request->attributes->set('registryGroup', $domain->group);
            $request->attributes->set('registryDomainMode', true);
            $request->attributes->set('registryOciNamePrefix', '');

            return $next($request);
        }

        $name = $request->route('name');

        if ($name === null) {
            // The bare `GET /v2/` (and the `/v2` routing fallback beside it) name no
            // repository at all, so on a non-domain host there is nothing to split and no
            // registry to resolve — the caller has not yet said which one it means. They
            // pass through WITHOUT `registryGroup`: VersionController answers the version
            // check from the credential alone (see its own docblock), and
            // EnsureRegistryTypeEnabled no-ops when no group was resolved, since there is
            // no organization whose enabled types it could ask about.
            return $next($request);
        }

        $segments = explode('/', (string) $name);

        // Three at minimum: an organization slug, a registry slug, and at least one segment
        // of repository name. Both slugs are single-segment by their own pattern, so this
        // split stays unambiguous for a repository name that itself contains slashes
        // (`3b/intern/team/app` → org `3b`, registry `intern`, repository `team/app`).
        //
        // Fewer than three is a plain 404 and NOT an OciException: the caller named nothing
        // that could exist, which is the same distinction ResolvesOciRepository already
        // draws between a malformed name (no body) and a merely unregistered one
        // (NAME_UNKNOWN). The two unresolved lookups below are plain 404s for the same
        // reason — an `errors[]` envelope there would confirm which half of the address was
        // the real one.
        abort_if(count($segments) < 3, 404);

        [$orgSlug, $groupSlug] = [array_shift($segments), array_shift($segments)];

        // Organization first, then the group within it — two seeks, not one query carrying
        // the organization as a correlated EXISTS. ResolveRegistryContext resolves the
        // identical org+registry slug pair for `/r/{orgSlug}/{groupSlug}` and its own
        // comment carries the measurement for why the composite index cannot be seeked on
        // its second column alone; the shape is reused here rather than restated.
        $organization = Organization::where('slug', $orgSlug)->first();
        abort_if($organization === null, 404);

        $group = $organization->groups()->where('slug', $groupSlug)->first();
        abort_if($group === null, 404);

        // The relation is genuinely this object — the group came out of its own hasMany —
        // and saying so keeps every later `$group->organization` (the registry-type gate
        // among them) from re-fetching a row this resolution already held.
        $group->setRelation('organization', $organization);

        $request->attributes->set('registryGroup', $group);
        $request->attributes->set('registryDomainMode', false);

        // The controllers know nothing of the two slugs: `{name}` must reach them as the
        // bare repository name, or every lookup would be against `3b/intern/meinapp`.
        $request->route()->setParameter('name', implode('/', $segments));

        // …but every URL the registry hands BACK has to be expressed in the caller's own
        // address space again, so the namespace that was stripped is kept here rather than
        // reconstructed later from the raw path. A `Location` built from the rewritten name
        // alone points at `/v2/meinapp/blobs/uploads/<id>`, which names no organization and
        // no registry: on this host that is fewer than three segments and therefore a 404 —
        // and a client follows an upload-session Location without asking, so the push dies
        // there. Found by a real `docker push` through the path address (bin/e2e), not by
        // reading the controllers; see ResolvesOciRepository::ociAddressedName().
        $request->attributes->set('registryOciNamePrefix', "{$orgSlug}/{$groupSlug}/");

        return $next($request);
    }
}
