<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Group;
use App\Models\Package;
use App\Services\Scope\OrgScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Helpers that keep per-organization admin controllers inside the organizations the
 * current user may administer, intersected with the active sidebar scope. This is the
 * security boundary that lets a customer-org admin into the console without seeing or
 * touching another organization's data.
 */
trait ScopesToAdministeredOrgs
{
    use GuardsPackageAttachment;

    /**
     * Organization ids the current request may read/list: the active scope, already
     * clamped to the user's administered organizations.
     *
     * @return list<string>
     */
    protected function scopedOrgIds(): array
    {
        return app(OrgScope::class)->ids();
    }

    /** Aborts 403 unless the current user may administer the given organization. */
    protected function assertAdministersOrg(?string $organizationId): void
    {
        abort_unless($organizationId !== null && Auth::user()?->administers($organizationId), 403);
    }

    /** Aborts 403 unless the current user may administer the registry's organization. */
    protected function assertAdministersGroup(Group $group): void
    {
        $this->assertAdministersOrg($group->organization_id);
    }

    /**
     * Constrains a Group query to the active scope. A super-admin viewing "all orgs"
     * gets no filter; everyone else is clamped to their administered organizations.
     *
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    protected function scopeGroupQuery(Builder $query): Builder
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return $query;
        }

        return $query->whereIn('organization_id', $this->scopedOrgIds());
    }

    /**
     * Constrains a Package query to packages owned within the active scope. Packages
     * carry their organization outright (`organization_id`). A super-admin viewing
     * "all orgs" sees every package; everyone else only sees packages owned by one of
     * their administered organizations.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    protected function scopePackageQuery(Builder $query): Builder
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return $query;
        }

        return $query->whereIn('organization_id', $this->scopedOrgIds());
    }

    /**
     * Constrains a Package query to what may be *assigned* to a registry in the active
     * scope, which since the shared-packages feature is wider than what the scope owns:
     * own packages, OR shared ones whose owning organization this caller administers.
     *
     * Deliberately not the same question as {@see scopePackageQuery}, which stays the
     * listing/ownership scope — a shared package of the operator organization is not the
     * customer's to see in their package directory, edit or delete; it is only theirs to
     * receive. This one exists so the assignment picker offers exactly the set
     * {@see GuardsPackageAttachment::assertPackagesReachableIn()} will accept: a picker
     * offering less hides the feature, one offering more produces a 403 on submit.
     *
     * That guard, not this method, is the security boundary. This is a listing filter, and
     * the two are kept in step by the tests deriving both from the same sentence — and by
     * both reading the caller's administered organizations from the one accessor,
     * {@see GuardsPackageAttachment::administeredOrganizationIds()}.
     *
     * The second clause is what makes spec §4's two gates two. Before the ownership half
     * was added here, the picker offered every shared package to every organization admin,
     * which is how a customer came to be able to help themselves to a package the operator
     * had never assigned them. It is also a disclosure fix in its own right: the names of
     * the packages the operator shares with other customers are not a customer's to search.
     *
     * The `spansAllOrganizations()` branch needs no shared clause: a scope that sees every
     * organization already sees every shared package, since a shared package is owned by
     * one of them — and that branch is only ever a super-admin, who administers every
     * organization and so satisfies the second clause too. Adding `orWhere('shared', true)`
     * to an otherwise unconstrained query would in fact NARROW it to shared packages only,
     * because an empty nested where is dropped by the query builder and the `or` would
     * become the whole condition.
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    protected function scopeAssignablePackageQuery(Builder $query): Builder
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return $query;
        }

        $scopedOrgIds = $this->scopedOrgIds();
        $administeredOrgIds = $this->administeredOrganizationIds();

        // Nested, so a caller's own `where` on top of this (the name filter) cannot be
        // swallowed by the `or`; and the shared arm nested again, so both halves of the
        // exception have to hold together — exactly as the guard states them.
        return $query->where(fn (Builder $reachable) => $reachable
            ->whereIn('organization_id', $scopedOrgIds)
            ->orWhere(fn (Builder $shared) => $shared
                ->where('shared', true)
                ->whereIn('organization_id', $administeredOrgIds)));
    }

    /** Aborts 403 unless the given package is owned within the active scope. */
    protected function assertCanTouchPackage(Package $package): void
    {
        if (app(OrgScope::class)->spansAllOrganizations()) {
            return;
        }

        abort_unless(in_array($package->organization_id, $this->scopedOrgIds(), true), 403);
    }

    /**
     * Aborts 403 unless every submitted package is owned by the organization it is being
     * attached into, or is a shared package of an organization the caller administers. A
     * package owned elsewhere is refused, otherwise attaching it would hand the caller
     * write access to it via assertCanTouchPackage().
     *
     * Checked against the target organization specifically, not the caller's broader
     * scope: an admin who administers several organizations must not be able to move a
     * package from one of them into another just because both are reachable, and the
     * migration that hardened `packages.organization_id` refuses exactly such a
     * cross-organization `group_package` row on the next fresh install — so this holds
     * even for a super-admin spanning every organization, which is why there is no
     * spansAllOrganizations() exemption here unlike the rest of this trait.
     *
     * The decision itself lives in {@see GuardsPackageAttachment}, shared with `/api/v1`;
     * only how the target organization is resolved differs.
     *
     * @param  array<int, string>  $packageIds
     */
    protected function assertCanAttachPackages(array $packageIds, string $organizationId): void
    {
        $this->assertPackagesReachableIn($packageIds, [$organizationId]);
    }

    /**
     * Resolves the organization a newly created object belongs to: the active scope
     * when one is selected, otherwise the explicitly provided id — validated to be one
     * the user may administer.
     */
    protected function resolveCreationOrg(?string $requested): string
    {
        $active = app(OrgScope::class)->creationOrganizationId();

        // Prefer an explicit choice, then the active scope, then — when neither is set
        // (e.g. a super-admin viewing "all orgs" who didn't pick one) — the user's own
        // home organization. Always validated to be one the user may administer.
        $orgId = $requested ?: ($active ?: Auth::user()?->organization_id);

        $this->assertAdministersOrg($orgId);

        return (string) $orgId;
    }
}
