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
     * Schreib-Autorisierung fuer den Publish-Pfad. Bewusst OHNE den public-Kurzschluss
     * aus canAccessGroup(): eine oeffentlich LESBARE Registry darf nicht von jedem
     * beschrieben werden. Ein Token darf nur publishen, wenn er zur Ziel-Org gehoert
     * (und, falls group-scoped, exakt zur Ziel-Group) und Publish-Faehigkeit hat.
     * Ohne diese Trennung koennte ein org-fremder Publish-Token eine eingeschleuste
     * Version an ein global geteiltes Package haengen (Supply-Chain-Injection).
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
     * Ob ein Package der Ziel-Group zugeordnet ist (nicht abgelaufen). Fuer den
     * Schreib-Pfad: die Group-Autorisierung muss bereits ueber canPublishToGroup()
     * erfolgt sein — hier wird nur die Package-Zugehoerigkeit org/group-strikt geprueft,
     * ohne jeden public-Kurzschluss.
     */
    public function packageBelongsToGroup(Group $group, Package $package): bool
    {
        return $this->availablePackages($group)->whereKey($package->id)->exists();
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
