<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Group;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Rights-scoping for the versioned REST API. Unlike the web console there is no sidebar
 * scope switch — the API always exposes exactly what the authenticated key's owner may
 * see and do, by their per-organization roles:
 *
 *   - read  = every organization the user belongs to (home + memberships), super = all
 *   - write = every organization the user administers (admin/maintainer),  super = all
 *
 * A robot is just a user account, so it inherits the same rules from its role/flag.
 */
trait ScopesApiToUser
{
    use GuardsPackageAttachment;

    private function apiUser(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** A super-admin is never constrained — short-circuits the org filters. */
    protected function seesAllOrganizations(): bool
    {
        return $this->apiUser()->isSuperAdmin();
    }

    /**
     * Organizations the caller may read from (home org + additional memberships).
     *
     * @return list<string>
     */
    protected function readableOrgIds(): array
    {
        return $this->apiUser()->accessibleOrganizationIds();
    }

    /**
     * @param  Builder<Group>  $query
     * @return Builder<Group>
     */
    protected function scopeGroupRead(Builder $query): Builder
    {
        if ($this->seesAllOrganizations()) {
            return $query;
        }

        return $query->whereIn('organization_id', $this->readableOrgIds());
    }

    /**
     * Packages carry their organization outright (`organization_id`).
     *
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    protected function scopePackageRead(Builder $query): Builder
    {
        if ($this->seesAllOrganizations()) {
            return $query;
        }

        return $query->whereIn('organization_id', $this->readableOrgIds());
    }

    protected function assertCanReadGroup(Group $group): void
    {
        abort_unless(
            $this->seesAllOrganizations() || in_array($group->organization_id, $this->readableOrgIds(), true),
            403,
        );
    }

    protected function assertCanReadPackage(Package $package): void
    {
        if ($this->seesAllOrganizations()) {
            return;
        }

        abort_unless(in_array($package->organization_id, $this->readableOrgIds(), true), 403);
    }

    /** Aborts 403 unless the caller administers (admin/maintainer, or super) the org. */
    protected function assertCanWriteOrg(?string $organizationId): void
    {
        abort_unless($organizationId !== null && $this->apiUser()->administers($organizationId), 403);
    }

    protected function assertCanWriteGroup(Group $group): void
    {
        $this->assertCanWriteOrg($group->organization_id);
    }

    /** A package is writable if it is owned by an organization the caller administers (or super). */
    protected function assertCanWritePackage(Package $package): void
    {
        if ($this->seesAllOrganizations()) {
            return;
        }

        abort_unless(
            in_array($package->organization_id, $this->apiUser()->administeredOrganizationIds(), true),
            403,
        );
    }

    /**
     * Aborts 403 unless every submitted package is owned by the organization it is being
     * attached into. A package owned elsewhere is refused, otherwise attaching it would
     * grant write access to it through assertCanWritePackage().
     *
     * Checked against the target organization specifically, not the caller's broader
     * administered set: a key owner who administers several organizations must not be
     * able to move a package from one of them into another just because both are
     * administered, and the migration that hardened `packages.organization_id` refuses
     * exactly such a cross-organization `group_package` row on the next fresh install —
     * so this holds even for a super-admin, which is why there is no
     * seesAllOrganizations() exemption here unlike the rest of this trait.
     *
     * The decision itself lives in {@see GuardsPackageAttachment}, shared with the web
     * console; only how the target organization is resolved differs.
     *
     * @param  array<int, string>  $packageIds
     */
    protected function assertCanAttachPackages(array $packageIds, string $organizationId): void
    {
        $this->assertPackagesReachableIn($packageIds, [$organizationId]);
    }

    /**
     * The organization a newly created object belongs to: an explicit choice, else the
     * caller's home org — always validated to be one the caller administers.
     */
    protected function resolveWriteOrg(?string $requested): string
    {
        $orgId = $requested ?: $this->apiUser()->organization_id;
        $this->assertCanWriteOrg($orgId);

        return (string) $orgId;
    }
}
