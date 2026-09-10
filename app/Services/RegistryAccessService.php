<?php

namespace App\Services;

use App\Enums\PackageType;
use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RegistryAccessService
{
    public function canAccessGroup(?RegistryToken $token, Group $group): bool
    {
        if ($group->public) {
            return true;
        }

        if (! $token) {
            return false;
        }

        if ($token->group_id !== null) {
            return $token->group_id === $group->id;
        }

        // groups.organization_id is database-enforced NOT NULL (see the
        // 2026_09_02_110000_enforce_package_organization migration) for a *persisted* row —
        // but this method takes plain models, and Eloquent never guarantees every attribute
        // of an in-memory model is set. getAttribute() reads the raw value instead of the
        // magic property, whose declared type PHPStan infers from that same NOT NULL schema
        // and would otherwise treat as always non-null — masking exactly the case this
        // guards against. Dropping either null check would let two unset organization_ids
        // compare equal and grant access neither side actually has.
        $groupOrganizationId = $group->getAttribute('organization_id');
        $tokenOrganizationId = $token->getAttribute('organization_id');

        return $groupOrganizationId !== null
            && $tokenOrganizationId !== null
            && $tokenOrganizationId === $groupOrganizationId;
    }

    /**
     * Read authorization for the org-level aggregate registry (`/o/{orgSlug}`). The
     * org-wide twin of canAccessGroup(), WITHOUT its public shortcut (spec decision 4):
     * the aggregate spans every group of the organization, including private ones, so a
     * single public group in the org must not leak org-wide anonymous access — a caller
     * without a matching token gets nothing here regardless of any group's `public` flag.
     *
     * Only an org-wide token (`group_id === null`) of the SAME organization qualifies. A
     * group-scoped token never widens past the one group it was issued for, even when
     * that group belongs to the right organization — canAccessGroup() is the narrower
     * check for that case, and this method does not fall back to it.
     */
    public function canAccessOrganization(?RegistryToken $token, Organization $organization): bool
    {
        if (! $token || $token->group_id !== null) {
            return false;
        }

        // Same defensive shape as canAccessGroup()'s org-wide branch, and the same reason:
        // read via getAttribute() rather than the magic property, whose declared type
        // PHPStan infers as always non-null from the NOT NULL schema of a *persisted* row.
        // This method takes plain models, and an in-memory one is not guaranteed to have
        // every attribute set — dropping either null check would let two unset ids compare
        // equal and grant access neither side actually has.
        $tokenOrganizationId = $token->getAttribute('organization_id');
        $organizationId = $organization->getAttribute('id');

        return $tokenOrganizationId !== null
            && $organizationId !== null
            && $tokenOrganizationId === $organizationId;
    }

    /**
     * Write authorization for the publish path. Deliberately WITHOUT the public
     * short-circuit from canAccessGroup(): a publicly READABLE registry must not be
     * writable by everyone. A token may only publish if it belongs to the target org
     * (and, if group-scoped, exactly to the target group) and has publish ability.
     * Without this separation, a publish token from a different org could attach an
     * injected version to a globally shared package (supply-chain injection).
     */
    public function canPublishToGroup(?RegistryToken $token, Group $group): bool
    {
        if (! $token || $token->ability !== TokenAbility::Publish) {
            return false;
        }

        // Same defensive shape as canAccessGroup() above, and the same reason for reading
        // via getAttribute() rather than the magic property: groups.organization_id is
        // database-enforced NOT NULL for a persisted row, but this still refuses rather
        // than compare two unset organization_ids as equal. See the comment there.
        $groupOrganizationId = $group->getAttribute('organization_id');
        $tokenOrganizationId = $token->getAttribute('organization_id');

        if ($groupOrganizationId === null || $tokenOrganizationId === null
            || $tokenOrganizationId !== $groupOrganizationId) {
            return false;
        }

        if ($token->group_id !== null) {
            return $token->group_id === $group->id;
        }

        return true;
    }

    /**
     * Whether a package is assigned to the target group (not expired). For the
     * write path: group authorization must already have happened via
     * canPublishToGroup() — here only package membership is checked strictly by
     * org/group, without any public short-circuit.
     */
    public function packageBelongsToGroup(Group $group, Package $package): bool
    {
        return $this->availablePackages($group)->whereKey($package->id)->exists();
    }

    /**
     * The group's pool packages without expired assignments.
     *
     * @return Collection<int, Package>
     */
    public function packagesFor(Group $group): Collection
    {
        return $this->availablePackages($group)->get();
    }

    public function canAccessPackage(?RegistryToken $token, Group $group, Package $package): bool
    {
        return $this->canAccessGroup($token, $group)
            && $this->availablePackages($group)->whereKey($package->id)->exists();
    }

    /**
     * Every package visible through ANY group of the organization — the union of
     * availablePackages() across all of the org's groups, generalized into one query
     * rather than one query per group. Deduplicated by package id.
     *
     * @return Collection<int, Package>
     */
    public function packagesForOrganization(Organization $organization): Collection
    {
        return $this->organizationPackagesQuery($organization)->get();
    }

    /**
     * A single visible package by (type, name) — packages are unique per (organization,
     * type, name), so at most one row can ever match. Returns null both when no such
     * package exists at all and when it exists but is not assigned (unexpired) to any
     * group of the organization: this method answers visibility, not existence.
     */
    public function organizationPackage(Organization $organization, PackageType $type, string $name): ?Package
    {
        return $this->organizationPackagesQuery($organization)
            ->where('packages.type', $type)
            ->where('packages.name', $name)
            ->first();
    }

    /**
     * The query shared by packagesForOrganization() and organizationPackage(): every
     * package assigned (unexpired) to any group of the organization, owned by that
     * organization or shared — the same predicate availablePackages() states for a single
     * group, generalized across all of the organization's groups at once.
     *
     * ONE query, not one per group: the EXISTS produced by whereHas() correlates against
     * `groups`/`group_package` per package row rather than joining and multiplying rows,
     * so a package assigned to several of the organization's groups still yields exactly
     * one row with no DISTINCT needed. Column qualified with `packages.` only where the
     * `whereHas` subquery could otherwise ambiguously resolve against `groups`/
     * `group_package`.
     *
     * @return Builder<Package>
     */
    private function organizationPackagesQuery(Organization $organization): Builder
    {
        return Package::query()
            ->whereHas('groups', fn ($q) => $q
                ->where('groups.organization_id', $organization->id)
                ->where(fn ($q2) => $q2
                    ->whereNull('group_package.available_until')
                    ->orWhere('group_package.available_until', '>', now())))
            ->where(fn ($q) => $q
                ->where('packages.organization_id', $organization->id)
                ->orWhere('packages.shared', true));
    }

    /**
     * The single place for the ownership predicate of the group assignment — and the one
     * caller-facing statement of the expiry predicate, which the relation itself carries.
     *
     * Constrained to the addressed registry's organization — or shared — for the same reason
     * findLocal() and the PyPI read paths are: the pivot row records *assignment*, and
     * canAccessPackage() checks assignment and group access — neither compares the package's
     * organization to the registry's. A cross-organization pivot row would therefore be served
     * here, and this method feeds packagesFor() (Composer's `available-packages` index),
     * packageBelongsToGroup() (the npm publish membership check) and canAccessPackage() (every
     * ecosystem's access check). Without the constraint a registry with no Composer upstream
     * listed another tenant's package *name* in available-packages — name disclosure, not
     * content, since findLocal() still refused to serve it.
     *
     * A shared package is exactly the cross-organization row this refused, and now the one
     * kind that is legitimate: it is owned by the operator organization rather than by a
     * tenant (spec §1) and is deliberately offered to others. The pivot row is still what
     * grants access — an unassigned shared package appears in no registry.
     *
     * The enforcement migration is not what holds the non-shared half. It refuses on ANY
     * cross-organization row, shared or not — it has no `shared` clause and exempts nothing —
     * and it runs once, before `packages.shared` exists, so it constrains the data at that one
     * moment and says nothing about rows written afterwards. Every shared assignment this method
     * exists to serve is such a row. So the non-shared half is held by the write paths alone
     * (GuardsPackageAttachment), and stated here as well, because an invariant that only the read
     * paths spell out one by one is one edit from being lost.
     *
     * The operational consequence is an operator's rather than this method's: rolling the schema
     * back past 2026_09_03_120000_add_shared_to_packages.php drops the column while the shared
     * assignments stay behind, so re-running the enforcement migration from there aborts and names
     * every one of them as a violation. Recorded in docs/development.md, "Shared packages".
     *
     * @return BelongsToMany<Package, Group, GroupPackage>
     */
    private function availablePackages(Group $group): BelongsToMany
    {
        // The expiry predicate itself lives on the relation (Group::assignedPackages), so
        // the attach-time guard asks the same question of a row that this read path does.
        // Columns qualified because the relation query joins `group_package`.
        return $group->assignedPackages()
            ->where(fn ($q) => $q
                ->where('packages.organization_id', $group->organization_id)
                ->orWhere('packages.shared', true));
    }
}
