<?php

namespace App\Services\Portal;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\RegistryAccessService;
use Illuminate\Support\Collection;

class PortalPackages
{
    public function __construct(private readonly RegistryAccessService $access) {}

    /**
     * Every package available to this organization, its own and those shared with it,
     * with the registries that carry it and whether the assignment is still in force.
     *
     * This states NO rule of its own, and that is deliberate. What a registry serves is
     * RegistryAccessService::packagesFor() — the public face of its private
     * availablePackages(), which carries both the expiry predicate (through
     * Group::assignedPackages()) and the own-or-shared constraint. What is assigned to a
     * registry is Group::packages(), the unfiltered pivot. Lapsed is the difference between
     * the two, computed by set membership. A date comparison here would be a second
     * statement of the expiry rule, and this codebase has paid repeatedly for one rule
     * written in two places — most recently a console field that made an identity check
     * where the resolver made an existence check, and told the operator in German to do
     * something destructive that would not have helped.
     *
     * Group::packages() and not Group::assignedPackages() for the inner loop, equally
     * deliberately: reading the filtered relation here would drop a lapsed assignment from
     * the list entirely, which is the shape the per-registry portal surfaces have and the
     * one this page exists NOT to have. The customer's build gets a 404 for such a package,
     * and this is the page where that becomes explicable. See PortalPackagesTest.
     *
     * @return Collection<int, array{package: Package, groups: Collection<int, Group>, in_force: bool}>
     */
    public function for(Organization $organization): Collection
    {
        /** @var Collection<string, array{package: Package, groups: Collection<int, Group>, in_force: bool}> $rows */
        $rows = collect();

        foreach ($organization->groups()->orderBy('name')->get() as $group) {
            $served = $this->access->packagesFor($group)->keyBy('id');

            foreach ($group->packages()->get() as $package) {
                $row = $rows->get($package->id) ?? [
                    'package' => $package,
                    'groups' => collect(),
                    'in_force' => false,
                ];

                $row['groups'] = $row['groups']->push($group);
                // In force anywhere is in force for the list: the package is usable, and
                // the registry column says through which registry. Accumulating, not
                // last-wins — the registries need not agree.
                $row['in_force'] = $row['in_force'] || $served->has($package->id);

                $rows->put($package->id, $row);
            }
        }

        return $rows->values()->sortBy(fn (array $r): string => $r['package']->name)->values();
    }
}
