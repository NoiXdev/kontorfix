<?php

namespace App\Services\Portal;

use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Services\RegistryAccessService;
use Illuminate\Support\Carbon;
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
     * Each entry is a PortalRegistryAssignment, which also carries `available_until` — the end
     * date of THAT registry's assignment, so
     * the page can say "abgelaufen am …" (spec §3) against the registry it actually belongs to.
     * IT IS A STORED VALUE PASSED THROUGH: this service reads the column and compares it to
     * nothing. The expiry decision stays exactly where it was, in the difference between
     * packagesFor() and packages() — a date in the returned shape is not a date rule in the
     * code, and no reader should take it for one. It is carried here because the only other
     * place a caller could reach for it, $row['package']->pivot, is the pivot of whichever
     * registry was seen FIRST, which in a mixed row is silently the wrong registry's date.
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
     * @return Collection<int, array{package: Package, groups: Collection<int, PortalRegistryAssignment>, in_force: bool}>
     */
    public function for(Organization $organization): Collection
    {
        /** @var array<string, array{package: Package, groups: list<PortalRegistryAssignment>}> $rows */
        $rows = [];

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
                // getAttribute() rather than ->pivot: the pivot lives among the model's
                // relations rather than its attributes, so the magic property is invisible to
                // static analysis. It is a GroupPackage because Group::packages() declares
                // ->using().
                /** @var GroupPackage $assignment */
                $assignment = $package->getAttribute('pivot');

                // Read and handed on, never compared. The column is nullable — an assignment
                // with no end date has none — and that null passes through unchanged.
                /** @var Carbon|null $availableUntil */
                $availableUntil = $assignment->available_until;

                $rows[$package->id] ??= ['package' => $package, 'groups' => []];

                // The per-registry answer, and the only place any `in_force` is decided.
                // Appended into the stored row itself, so there is no write-back to forget and
                // none to mistake for one (an earlier Collection here made the write-back a
                // no-op, because push() mutates in place).
                $rows[$package->id]['groups'][] = new PortalRegistryAssignment(
                    group: $group,
                    in_force: $served->has($package->id),
                    available_until: $availableUntil,
                );
            }
        }

        $ordered = array_values($rows);
        usort($ordered, fn (array $a, array $b): int => strcmp($a['package']->name, $b['package']->name));

        $result = [];

        foreach ($ordered as $row) {
            // Wrapped once, here: the entries are composed as a plain list above, where appending
            // into the stored row needs no write-back, and the list is finished by now.
            $groups = collect($row['groups']);

            $result[] = [
                'package' => $row['package'],
                'groups' => $groups,
                // Derived from the entries, never accumulated alongside them: in force in at
                // least one registry. The package is usable, and the registry column says
                // through which ones.
                'in_force' => $groups->contains(fn (PortalRegistryAssignment $entry): bool => $entry->in_force),
            ];
        }

        return collect($result);
    }
}
