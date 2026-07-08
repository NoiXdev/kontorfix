<?php

namespace App\Services;

use App\Models\Group;
use App\Models\Package;
use App\Models\RegistryToken;
use Illuminate\Database\Eloquent\Collection;

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
        return $group->packages()
            ->where(function ($q) {
                $q->whereNull('group_package.available_until')
                    ->orWhere('group_package.available_until', '>', now());
            })
            ->get();
    }

    public function canAccessPackage(?RegistryToken $token, Group $group, Package $package): bool
    {
        return $this->canAccessGroup($token, $group)
            && $this->packagesFor($group)->contains('id', $package->id);
    }
}
