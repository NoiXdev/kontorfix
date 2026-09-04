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
     * Every package available to this organization, its own and those shared with it, with the
     * registries that carry it and whether the assignment is still in force IN EACH OF THEM.
     *
     * `in_force` exists twice in the returned shape and is written ONCE. Each entry of `groups`
     * carries the answer for that registry, and the row-level flag is DERIVED from the entries
     * — in force in at least one of them. Accumulating the row flag separately in the loop is
     * the shape this deliberately avoids: two computations of one rule can disagree, and here
     * they disagree exactly in the case that matters, a package live in one registry and lapsed
     * in another. A row that read "in force" while offering an unmarked link to the registry
     * that 404s the customer's build is this task's own defect one level down.
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
     * ONE filter is applied here, and it is NOT a rule of its own: `groups.portal_enabled` —
     * "does this registry appear in the portal", the same predicate
     * Portal\RegistryController::index() already asks of the same column. Without it this page
     * would name a registry the portal deliberately hides and render a link into it, and
     * GroupPolicy::view() would answer 403 to a link the portal itself produced. The admin
     * registry page already replaced exactly that shape — row actions the caller may not use
     * are hidden rather than shown and then refused — and on the customer-facing page it is
     * worse, because a customer cannot know why.
     *
     * The consequence is intended, not incidental: A PACKAGE REACHABLE ONLY THROUGH HIDDEN
     * REGISTRIES DOES NOT APPEAR IN THE PORTAL AT ALL. That is what the switch means. It
     * governs the portal surface and never the registry endpoints, so the customer can still
     * resolve such a package through /r/… with a token.
     *
     * Nothing about EXPIRY moves into that filter. Expiry remains the difference between
     * packagesFor() and packages(), and there is still no date comparison anywhere here.
     *
     * Group::packages() and not Group::assignedPackages() for the inner loop, equally
     * deliberately: reading the filtered relation here would drop a lapsed assignment from
     * the list entirely, which is the shape the per-registry portal surfaces have and the
     * one this page exists NOT to have. The customer's build gets a 404 for such a package,
     * and this is the page where that becomes explicable. See PortalPackagesTest.
     *
     * @return Collection<int, array{package: Package, groups: Collection<int, array{group: Group,
     *     in_force: bool}>, in_force: bool}>
     */
    public function for(Organization $organization): Collection
    {
        /** @var Collection<string, array{package: Package, groups: Collection<int, array{group: Group, in_force: bool}>}> $rows */
        $rows = collect();

        $groups = $organization->groups()
            // `groups.portal_enabled`, not `organizations.portal_enabled`: whether THIS
            // REGISTRY appears in the portal. Whether the organization has a portal at all was
            // already answered upstream by ResolvePortalContext.
            ->where('portal_enabled', true)
            ->orderBy('name')
            ->get();

        foreach ($groups as $group) {
            $served = $this->access->packagesFor($group)->keyBy('id');

            foreach ($group->packages()->get() as $package) {
                $row = $rows->get($package->id) ?? [
                    'package' => $package,
                    'groups' => collect(),
                ];

                // The per-registry answer, and the only place any `in_force` is decided.
                $row['groups'] = $row['groups']->push([
                    'group' => $group,
                    'in_force' => $served->has($package->id),
                ]);

                $rows->put($package->id, $row);
            }
        }

        return $rows->values()
            ->map(fn (array $r): array => [
                'package' => $r['package'],
                'groups' => $r['groups'],
                // Derived, never accumulated alongside: in force in at least one registry. The
                // package is usable, and the registry column says through which ones.
                'in_force' => $r['groups']->contains(fn (array $e): bool => $e['in_force']),
            ])
            ->sortBy(fn (array $r): string => $r['package']->name)
            ->values();
    }
}
