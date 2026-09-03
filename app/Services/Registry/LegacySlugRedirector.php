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
 * The lookup goes through `groups.legacy_slug`, never through the live `slug`, and that is
 * the whole safety property. Before this branch a registry slug was globally unique
 * (`groups.slug` had its own unique index), so a bare slug always named exactly one
 * registry. 2026_09_03_100000 scoped that uniqueness to (organization_id, slug) — the point
 * of the branch, two customers may each call a registry `packages` — and in the same
 * statement froze each existing registry's one-segment address into `legacy_slug` under a
 * unique index of its own.
 *
 * Matching the live slug instead would mean that the moment a *second* organization created
 * a registry called `packages`, the first organization's un-migrated clients stopped
 * resolving — silently, with the creating admin seeing success and the affected tenant
 * seeing nothing, and needing no cross-org rights at all. Worse, an organization that
 * renamed its own slug freed that name for a registry elsewhere, and a stale client of the
 * renamed organization would then have been 301'd onto a stranger's tarball.
 *
 * Frozen addresses make both impossible rather than merely detected:
 *   - a registry created after the migration has `legacy_slug = NULL` and can never capture
 *     an incumbent's legacy address;
 *   - renaming a registry clears the column (Group::booted()), so a released name stops
 *     answering instead of becoming a permanent alias;
 *   - the unique index means a legacy slug can never match two rows, so there is no
 *     ambiguity left to guess at or to refuse — see resolve().
 */
class LegacySlugRedirector
{
    /**
     * The one registry that still answers to this pre-upgrade address, or null when none
     * does — an unknown address, one that was given up in a rename, or one that never
     * existed because the registry was created after the upgrade. Null 404s upstream.
     *
     * `first()` and no ambiguity check: `legacy_slug` carries a unique index (added
     * alongside the column in 2026_09_03_100000), so two rows cannot share one legacy
     * address. An earlier revision of this class matched the live `slug`, where two rows
     * genuinely could match, and refused to pick between them; that refusal is gone
     * because the state it guarded is no longer constructible — not even directly against
     * the models, which is where the tests used to build it. A check that cannot fire is
     * worse than no check: it suggests the ambiguity is still possible.
     */
    public function resolve(string $slug): ?Group
    {
        return Group::with('organization')->where('legacy_slug', $slug)->first();
    }

    /**
     * The redirect target for $group, given how many of the request's leading
     * "/"-separated path segments to drop (the leading slash itself never produces a
     * segment — "r" and the legacy slug segment are what get dropped).
     *
     * Built from undecoded input rather than from decoded route parameters or
     * Request::getQueryString(): both decode-then-reencode, which corrupts a rest segment
     * that already carries percent-encoding (e.g. a project name containing %2F or %3F)
     * and reorders/collapses/reencodes a query string (Symfony's getQueryString() runs it
     * through parse_str + ksort + http_build_query). Byte-for-byte passthrough is what
     * preserves a project name or query value exactly as the client sent it.
     *
     * The path comes from getPathInfo() and only the query string from the raw REQUEST_URI.
     * getPathInfo() is undecoded too — Symfony's preparePathInfo() slices REQUEST_URI, it
     * does not urldecode — so nothing is lost, and it additionally strips the base URL.
     * Splitting the raw REQUEST_URI instead used to drop $skipSegments counted from the
     * *host* root, which is only the same thing when the application is deployed at the
     * document root. Under a subdirectory deployment — which AppUrl explicitly supports and
     * RegistryUrl::origin() propagates — /sub/r/tools/… dropped "sub" and "r", left "tools"
     * in the rest, and produced /sub/r/{org}/{group}/tools/… with the registry segment
     * doubled. The target is built relative to the application root (the redirect() helper
     * prepends the root again), so the base URL must not be part of it.
     */
    public function target(Group $group, Request $request, RegistryUrl $urls, int $skipSegments): string
    {
        $requestUri = (string) $request->server->get('REQUEST_URI', '');
        [, $rawQuery] = array_pad(explode('?', $requestUri, 2), 2, null);

        $segments = explode('/', ltrim($request->getPathInfo(), '/'));
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
