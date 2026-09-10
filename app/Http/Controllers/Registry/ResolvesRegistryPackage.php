<?php

namespace App\Http\Controllers\Registry;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\Http\AppUrl;
use App\Services\Registry\RegistryUrl;
use App\Services\RegistryAccessService;
use App\Services\Upstream\UpstreamCache;
use Illuminate\Http\Request;

trait ResolvesRegistryPackage
{
    abstract protected function access(): RegistryAccessService;

    protected function authorizeGroup(Request $request, Group $group): void
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');
        if (! $this->access()->canAccessGroup($token, $group)) {
            abort($token ? 404 : 401, 'Authentication required for this registry.');
        }
    }

    protected function registryGroup(Request $request): Group
    {
        /** @var Group $group */
        $group = $request->attributes->get('registryGroup');

        return $group;
    }

    /**
     * The org-context counterpart of authorizeGroup() — the branching every org read
     * endpoint (Composer today; npm/pypi copy this) must run BEFORE any package-name
     * resolution, so a caller without the right token can never use a 403 to learn whether
     * a name exists (spec's "no enumeration oracle" requirement).
     *
     * Three outcomes, in order:
     *  - access granted (an org-wide token of this organization) → return.
     *  - no token at all → 401, same message/shape authorizeGroup() sends for its own
     *    anonymous case, so an org caller and a group caller see the same challenge.
     *  - a token that does NOT grant access → 403, with ONE of two exact German messages
     *    (spec's error table): a token that IS for this organization but is group-bound
     *    names the org-wide-token requirement; anything else (a foreign organization's
     *    token, group-bound or not) gets the generic refusal. These are the only two ways
     *    canAccessOrganization() can be false for a non-null token, so the branch below is
     *    exhaustive.
     */
    protected function authorizeOrganization(Request $request, Organization $organization): void
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        if ($this->access()->canAccessOrganization($token, $organization)) {
            return;
        }

        abort_if($token === null, 401, 'Authentication required for this registry.');

        if ($token->organization_id === $organization->id && $token->group_id !== null) {
            abort(403, 'Dieses Token gilt nur für eine einzelne Registry — für die organisationsweite Quelle wird ein organisationsweites Token benötigt.');
        }

        abort(403, 'Kein Zugriff auf diese Organisation.');
    }

    /**
     * Absolute base for org-endpoint download URLs — the org-context counterpart of
     * registryBaseUrl(). No domain-mode branch here on purpose: the org endpoint has no
     * custom-domain address at all (spec non-goal), so ResolveRegistryContext never sets
     * `registryDomainMode` true alongside `registryOrganization`.
     */
    protected function registryBaseUrlForOrganization(Request $request, Organization $organization): string
    {
        return (AppUrl::root() ?? $request->getSchemeAndHttpHost()).$this->registryPathPrefixForOrganization($organization);
    }

    /**
     * Path prefix for org-endpoint metadata URLs: `/o/{orgSlug}`.
     *
     * Stated here rather than on RegistryUrl (which owns every per-group URL form) because
     * that class gains its own `orgPath()` builder later, for the portal setup snippets —
     * this is the one read path that needs the shape today, so it is not worth introducing
     * the shared builder ahead of that consumer just to save this one line.
     */
    protected function registryPathPrefixForOrganization(Organization $organization): string
    {
        return '/o/'.$organization->slug;
    }

    /**
     * Absolute base for the URLs handed to package clients (Composer `dist.url`, npm
     * `dist.tarball`, the PyPI index hrefs).
     *
     * Domain mode may use the request's host: ResolveRegistryContext has already matched
     * it against the `domains` table and 404s anything else, so it is a host this
     * instance answers to by definition. Slug mode may not — nothing there constrains
     * the `Host` header, so an injected one would end up as the download URL in every
     * client's lock file. It uses the configured application URL instead, which is the
     * same value every other generated link is rooted at (App\Http\Middleware\PinUrlRoot)
     * and is read through AppUrl, so a value written without a scheme still produces an
     * absolute download URL rather than a scheme-less one.
     */
    protected function registryBaseUrl(Request $request, Group $group): string
    {
        if ($request->attributes->get('registryDomainMode') === true) {
            return $request->getSchemeAndHttpHost();
        }

        return (AppUrl::root() ?? $request->getSchemeAndHttpHost()).app(RegistryUrl::class)->path($group);
    }

    /**
     * Path prefix for metadata URLs (e.g. metadata-url in packages.json): empty
     * for a custom domain (registry sits at the host root), otherwise /r/{orgSlug}/{groupSlug}.
     */
    protected function registryPathPrefix(Request $request, Group $group): string
    {
        return $request->attributes->get('registryDomainMode') === true
            ? ''
            : app(RegistryUrl::class)->path($group);
    }

    /**
     * Refuses a package-name segment that is a relative path component.
     *
     * The Composer `p2` and npm packument constraints (`[a-z0-9_.-]+`, `[a-z0-9._~-]+`,
     * `[a-z0-9._-]+`) all admit `.` and `..`. Neither can name a local package, so such a
     * request falls through to the upstream — where the segment is interpolated into the
     * outbound path, and where the answer is then written to `upstream_metadata_cache`
     * under that name. The registry routes carry no throttle by design, so those rows are
     * unbounded, and a caller that can choose `..` is choosing which upstream path is
     * fetched rather than which package.
     *
     * Reuses the refusal set the artifact cache key already uses. Its guard sits in
     * ProxyDownloadController and never covered these two paths, because the sinks here
     * are a URL path and a database key rather than a Flysystem key — but the values that
     * must not be admitted are exactly the same, and refusing them outright rather than
     * normalising them keeps the decision from being undone by a later pass.
     */
    protected function assertProxyableName(string ...$segments): void
    {
        foreach ($segments as $segment) {
            abort_unless(UpstreamCache::isSafeKeySegment($segment), 404);
        }
    }

    protected function findAccessible(Request $request, Group $group, PackageType $type, string $fullName): Package
    {
        $package = $this->findLocal($request, $group, $type, $fullName);
        if ($package === null) {
            abort(404); // deliberately not 403 — don't leak existence
        }

        return $package;
    }

    /**
     * Like findAccessible(), but doesn't abort — for callers that want to still
     * try an upstream fallback locally on a miss (Composer fallthrough).
     */
    protected function findLocal(Request $request, Group $group, PackageType $type, string $fullName): ?Package
    {
        /** @var RegistryToken|null $token */
        $token = $request->attributes->get('registryToken');

        // Own-organization, or shared. Still scoped, and for the unchanged reason: the name
        // is unique only within an organization, so an unscoped lookup could return another
        // tenant's package and then lean on the access check to hide it — a check that is
        // about assignment, not ownership. A shared package is the one exception the
        // namespace admits, because it is owned by the operator organization rather than by
        // a tenant (spec §1) and is deliberately offered to others. It still has to be
        // assigned to this registry — canAccessPackage() below enforces that through the
        // pivot — so sharing grants eligibility, not access.
        //
        // Two rows can now match: a customer's own package and a shared one of the same
        // name. Spec §5 settles which wins — the customer's own, never shadowed by something
        // the operator added — and the order says so rather than leaving the choice to
        // whichever row the database happens to hand back first. `packages.id` is a second,
        // semantically empty term: two shared packages of one name owned by two different
        // operator organizations are a pair spec §5 states no rule for, and inventing a
        // winner is not this method's job — but returning a *reproducible* one is.
        //
        // The access check is part of choosing the candidate, not a verdict passed on one
        // already chosen. Ordering first and checking afterwards would answer a request for
        // an assigned shared package with a 404 whenever the customer merely *owns* the same
        // name somewhere else: the own row sorts first, fails the assignment check, and the
        // shared row the operator did assign is never looked at. SharedAssignment permits
        // that state deliberately and correctly — it compares against what this registry
        // serves, and a package assigned to some other registry is not in it — so this is
        // reachable through the product, unlike the collision the order above settles.
        // App\Http\Controllers\Registry\PypiController::simpleProject() has always had the
        // check inside the predicate; this is the same shape.
        return Package::where('type', $type)
            ->where('name', $fullName)
            ->where(fn ($q) => $q
                ->where('packages.organization_id', $group->organization_id)
                ->orWhere('packages.shared', true))
            ->orderByRaw('(packages.organization_id = ?) desc, packages.id', [$group->organization_id])
            ->get()
            ->first(fn (Package $p): bool => $this->access()->canAccessPackage($token, $group, $p));
    }

    /**
     * Whether the name is hosted here — the dependency-confusion guard, which suppresses
     * the upstream fallthrough so a locally hosted name is never resolved from
     * packagist/npmjs.
     *
     * Two ways to host a name, and the two halves are deliberately scoped differently:
     *
     * 1. The addressed **organization** owns it. No assignment is asked about, and that is
     *    the point: a private package attached to no registry at all still must not have
     *    its name sent upstream. Scoped to that organization, because the name is — another
     *    organization's `acme/tools` is not this one's, and letting it suppress the
     *    fallthrough would let one tenant shadow another tenant's upstream dependency, which
     *    is this guard pointed the wrong way. Within its own namespace every organization is
     *    still fully protected, and that is the only namespace its clients resolve against.
     * 2. A **shared** package of that name is assigned to *this registry* (spec §4). Without
     *    this half, a customer resolving a shared name would be sent to Packagist or npmjs —
     *    precisely the confusion this guard exists to prevent, introduced by the feature
     *    meant to serve them. A shared package is owned by the operator organization
     *    (spec §1), so clause 1 never covers it in a customer's registry.
     *
     * The assignment in clause 2 is not decoration: a shared package this registry was never
     * handed is hosted by the instance but not *here*, and suppressing the fallthrough for it
     * would blank out a legitimate upstream dependency of that name in every registry the
     * operator did not share it with. Sharing grants eligibility, not access, on this path as
     * on every other.
     *
     * `packages()` and NOT `assignedPackages()`, which is the one place on this branch where
     * the guard deliberately outlives what the registry serves (spec §4, amended during
     * execution). An expired assignment is not the same situation as one that never existed:
     * the customer demonstrably did consume that name from here, so it is in their lock file
     * and their `composer.json`. Reading the expiry here would mean the next `composer update`
     * after an assignment lapses resolves that name from the public index — from whoever
     * registered it there — silently, with no act by anyone, in the guard whose whole purpose
     * is to prevent exactly that substitution. So the guard fails closed on anything this
     * registry has ever served: a lapsed share answers 404 and a loud build failure rather
     * than a quiet swap, which is the same choice spec §5 and the migration refusals make.
     * Detaching the assignment stays the operator's clean way to release a name back to the
     * public index — an explicit act opens the fallthrough, the passage of time does not.
     *
     * GIVEN the precondition every call site satisfies — authorizeGroup() has already passed,
     * so canAccessGroup() is true — this is wider than findLocal()'s served set by exactly
     * clause 1 plus lapsed shared assignments, and never narrower. Without that precondition
     * the comparison says nothing at all: findLocal() returns null for every row while this
     * still answers true, which is a guard erring closed and not a contradiction, but it is
     * not the subset relation stated above. Clause 1 diverges from findLocal() on purpose and
     * always has — that divergence is its entire reason for existing.
     *
     * @see tests/Feature/Registry/SharedPackageUpstreamTest.php — clause 2's positive
     * direction (a shared package assigned here counts as hosted) is reachable through HTTP
     * only via a lapsed assignment; for a live one, resolution answers first, so the direct
     * predicate tests in that file are its only coverage and are not an implementation
     * detail. Its negative direction is covered end-to-end as well.
     */
    protected function packageExistsLocally(PackageType $type, string $fullName, Group $group): bool
    {
        return Package::where('type', $type)
            ->where('name', $fullName)
            ->where(fn ($q) => $q
                ->where('packages.organization_id', $group->organization_id)
                ->orWhere(fn ($q2) => $q2
                    ->where('packages.shared', true)
                    ->whereIn('packages.id', $group->packages()->select('packages.id'))))
            ->exists();
    }
}
