<?php

namespace App\Services;

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
     * Pool-Pakete der Gruppe ohne abgelaufene Zuweisungen.
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
     * Die eine Stelle für das Ablauf-Prädikat der Gruppen-Zuweisung.
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
