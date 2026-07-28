<?php

namespace App\Services;

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\GroupPackage;
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

        return $group->organization_id !== null
            && $token->organization_id === $group->organization_id;
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

        if ($group->organization_id === null || $token->organization_id !== $group->organization_id) {
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
     * The single place for the expiry predicate of the group assignment.
     *
     * @return BelongsToMany<Package, Group, GroupPackage>
     */
    private function availablePackages(Group $group): BelongsToMany
    {
        return $group->packages()->where(function (Builder $q) {
            $q->whereNull('group_package.available_until')
                ->orWhere('group_package.available_until', '>', now());
        });
    }
}
