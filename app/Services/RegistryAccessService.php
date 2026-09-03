<?php

namespace App\Services;

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Package;
use App\Models\RegistryToken;
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
     * The enforcement migration now refuses to complete while a *non-shared* cross-organization
     * row exists, so that half should never match anything; it is stated anyway, because an
     * invariant that only the read paths spell out one by one is one edit from being lost.
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
