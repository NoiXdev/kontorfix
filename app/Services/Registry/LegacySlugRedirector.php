<?php

namespace App\Services\Registry;

use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Single source of truth for answering a pre-org-scoped /r/{slug}/... URL, whether it is
 * caught by the dedicated legacy route (LegacySlugRedirectController) or discovered later,
 * when the router already committed to the canonical two-segment route because its first
 * segment happened to satisfy {orgSlug} and the rest happened to satisfy some endpoint's
 * pattern one segment later. pip is the concrete case: GET /r/{slug}/simple/{project} reads
 * as {orgSlug}={slug}, {groupSlug}="simple", then npm's bare {package} pattern for
 * {project} — a real route match, so the dedicated legacy route below never runs at all,
 * and ResolveRegistryContext is where the fallback has to live instead (see there).
 *
 * Before this branch a registry slug was globally unique (`groups.slug` had its own unique
 * index), so a bare slug always named exactly one registry. Task 1 scoped that uniqueness to
 * (organization_id, slug), so two organizations may now legitimately hold the same registry
 * slug — which means a bare legacy slug can be genuinely ambiguous. Guessing which one a
 * legacy URL meant would silently swap one tenant's registry for another's: dependency
 * confusion if the guessed registry is public (the wrong `available-packages`/`p2` answers
 * with 200), a confusing 401/404 if it's private, and — because the lookup has no
 * deterministic ORDER BY — the guess can flip after any UPDATE or VACUUM changes heap order.
 * The migration (2026_09_03_100000_scope_group_slug_to_organization) and UnclaimedSlug both
 * already take the position that a wrong answer is worse than an error; this class applies
 * the same rule to the legacy redirect path: an ambiguous slug 404s instead of picking one.
 */
class LegacySlugRedirector
{
    /**
     * The one registry a legacy slug still identifies, or null if it identifies none or
     * more than one — both cases must 404 upstream, never guess (see class docblock).
     * take(2) is enough to tell "exactly one" from "more than one" without counting every
     * match.
     */
    public function resolve(string $slug): ?Group
    {
        $groups = Group::with('organization')->where('slug', $slug)->take(2)->get();

        return $groups->count() === 1 ? $groups->first() : null;
    }

    /**
     * The redirect target for $group, given how many of the request's leading
     * "/"-separated path segments to drop (the leading slash itself never produces a
     * segment — "r" and the legacy slug segment are what get dropped).
     *
     * Built from the untouched REQUEST_URI rather than decoded route parameters or
     * Request::getQueryString(): both decode-then-reencode, which corrupts a rest segment
     * that already carries percent-encoding (e.g. a project name containing %2F or %3F)
     * and reorders/collapses/reencodes a query string (Symfony's getQueryString() runs it
     * through parse_str + ksort + http_build_query). Byte-for-byte passthrough is what
     * preserves a project name or query value exactly as the client sent it.
     */
    public function target(Group $group, Request $request, RegistryUrl $urls, int $skipSegments): string
    {
        $requestUri = (string) $request->server->get('REQUEST_URI', '');
        [$rawPath, $rawQuery] = array_pad(explode('?', $requestUri, 2), 2, null);

        $segments = explode('/', ltrim($rawPath, '/'));
        $rest = implode('/', array_slice($segments, $skipSegments));

        $target = $urls->path($group).($rest === '' ? '' : '/'.$rest);

        return $rawQuery === null ? $target : $target.'?'.$rawQuery;
    }

    /**
     * 301 for a read (GET/HEAD): permanent, and every registry client already treats it as
     * safe to follow. 308 for a write (PUT publish, POST upload): permanent AND
     * method-and-body-preserving, so a redirected `npm publish` or `twine upload` does not
     * silently turn into a GET against the canonical URL and lose the payload.
     *
     * Deliberately `getMethod() === 'HEAD'` rather than `Request::isMethod('get')`: HEAD is
     * a read (package clients use it for existence/cache-validation checks against
     * composer.json/.npmrc-configured registries) but `isMethod('get')` is false for it, so
     * that check alone would wrongly hand a HEAD request a body-preserving 308 instead of
     * the plain 301 every client already expects for a read.
     */
    public function respond(string $target, Request $request): RedirectResponse
    {
        $isRead = in_array($request->getMethod(), ['GET', 'HEAD'], true);

        return redirect($target, $isRead ? 301 : 308);
    }
}
