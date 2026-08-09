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
     * @param  Builder<Package>  $query
     * @return Builder<Package>
     */
    protected function scopePackageRead(Builder $query): Builder
    {
        if ($this->seesAllOrganizations()) {
            return $query;
        }

        $orgIds = $this->readableOrgIds();

        return $query->whereHas('groups', fn (Builder $g) => $g->whereIn('organization_id', $orgIds));
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

        abort_unless($package->groups()->whereIn('organization_id', $this->readableOrgIds())->exists(), 403);
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

    /** A package is writable if it is attached to a registry in an administered org (or super). */
    protected function assertCanWritePackage(Package $package): void
    {
        if ($this->seesAllOrganizations()) {
            return;
        }

        abort_unless(
            $package->groups()->whereIn('organization_id', $this->apiUser()->administeredOrganizationIds())->exists(),
            403,
        );
    }

    /**
     * Aborts 403 unless every submitted package may be attached by the caller: it must
     * already be attached to a registry in an organization the key owner administers.
     * Pulling in a package that lives only in a foreign organization — or in none at all —
     * would otherwise grant write access to it through assertCanWritePackage().
     *
     * The decision itself lives in {@see GuardsPackageAttachment}, shared with the web
     * console; only the set of organizations that count as the caller's differs.
     *
     * @param  array<int, string>  $packageIds
     */
    protected function assertCanAttachPackages(array $packageIds): void
    {
        if ($this->seesAllOrganizations()) {
            return;
        }

        $this->assertPackagesReachableIn($packageIds, $this->apiUser()->administeredOrganizationIds());
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
