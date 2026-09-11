<?php

namespace App\Services\Portal;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\GroupPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Licence\VersionEntitlement;
use App\Services\RegistryAccessService;
use App\Support\Licence\VersionBounds;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PortalPackages
{
    public function __construct(
        private readonly RegistryAccessService $access,
        // Task 9's licence note. Only permits() is ever called on this — see
        // licenceNoteFor()'s docblock for why boundsFor() itself is not: this class already
        // holds the one pivot row boundsFor() would otherwise re-query for.
        private readonly VersionEntitlement $entitlement,
    ) {}

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
        /**
         * @var array<string, array{
         *     package: Package,
         *     groups: list<array{group: Group, in_force: bool, available_until: ?Carbon, bounds: VersionBounds}>
         * }> $rows
         */
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

                // Task 9's bounds, read the same way: straight off THIS pivot row, the one
                // belonging to this registry's own assignment. VersionBounds::fromPivot() is
                // the exact mapping VersionEntitlement::boundsFor() itself applies once it has
                // fetched an assignment row — and this loop already holds that row, fetched
                // once per registry by group->packages()->get() above, so asking boundsFor()
                // again here would spend one extra query per package re-deriving an answer
                // already in hand.
                $bounds = VersionBounds::fromPivot($assignment->version_min, $assignment->version_max);

                // The pivot is read and then DROPPED, because the model outlives this iteration:
                // the row keeps the first registry's Package instance, so leaving the relation
                // loaded would let a caller reach $row['package']->pivot->available_until and get
                // a real date belonging to the wrong registry. Unset, that mistake is a null
                // instead of a plausible wrong answer, and the per-registry date on the entry is
                // the only way to ask. Nothing in the returned shape needs the relation.
                $package->unsetRelation('pivot');

                $rows[$package->id] ??= ['package' => $package, 'groups' => []];

                // The per-registry answer, kept as plain data rather than a finished
                // PortalRegistryAssignment: the licence note below needs each package's own
                // version history loaded, and that happens once, in bulk, after this loop
                // finishes gathering every package this organization can reach — never once
                // per (group, package) pair here, which is what asking for it inside this loop
                // would make of it.
                $rows[$package->id]['groups'][] = [
                    'group' => $group,
                    'in_force' => $served->has($package->id),
                    'available_until' => $availableUntil,
                    'bounds' => $bounds,
                ];
            }
        }

        $ordered = array_values($rows);
        usort($ordered, fn (array $a, array $b): int => strcmp($a['package']->name, $b['package']->name));

        // ONE query for every row's own version history, exactly the shape
        // Portal\PackageController::index() already applies to the same rows for
        // `latest_version` — never one query per package, which is what asking
        // licenceNoteFor() to fetch $package->versions lazily, package by package, would
        // silently become.
        (new EloquentCollection(array_map(fn (array $row): Package => $row['package'], $ordered)))
            ->load('versions');

        $result = [];

        foreach ($ordered as $row) {
            $package = $row['package'];

            // Wrapped into PortalRegistryAssignment only now, once the licence note has
            // everything it needs: each entry's own bounds, gathered above, and every
            // row's package with its versions already loaded.
            $groups = collect($row['groups'])->map(fn (array $entry): PortalRegistryAssignment => new PortalRegistryAssignment(
                group: $entry['group'],
                in_force: $entry['in_force'],
                available_until: $entry['available_until'],
                licence: $this->licenceNoteFor($package, $entry['bounds']),
            ));

            $result[] = [
                'package' => $package,
                'groups' => $groups,
                // Derived from the entries, never accumulated alongside them: in force in at
                // least one registry. The package is usable, and the registry column says
                // through which ones.
                'in_force' => $groups->contains(fn (PortalRegistryAssignment $entry): bool => $entry->in_force),
            ];
        }

        return collect($result);
    }

    /**
     * Task 9: this ONE assignment's own upsell note — the highest version among the
     * package's own releases that its OWN bounds admit, and whether a newer release exists
     * that those bounds do not cover. Null wherever there is nothing to report: the bounds
     * are unlimited, or the package has no releases at all yet, or (the ordinary bounded
     * case) the bounds already admit the newest one.
     *
     * $bounds is THIS assignment's own window — VersionBounds::fromPivot() applied to the
     * pivot row `for()` already holds, the identical mapping VersionEntitlement::boundsFor()
     * itself would apply after a redundant query for the same row — never the union
     * VersionEntitlement::windowsForOrganization() would build across every one of the
     * organization's registries. A customer with two registries assigned to the same package
     * under two different windows sees each registry's own ceiling here, not a wider one that
     * would tell them nothing about the registry they are actually looking at — the same
     * registry-local scoping PortalRegistryAssignment::$available_until already keeps.
     *
     * Docker is refused before ever reaching permits(), which throws LogicException for it —
     * Docker carries no licence-bounded assignment concept at all (AssignmentWriter forces
     * its bounds absent), so a bound on a Docker pivot row can only be a hand-run mistake,
     * and this must not crash the portal's landing page over one.
     *
     * `Package::versions()` orders `released_at desc`, so `first()` is always the package's
     * own newest release and iterating in that same order finds the newest ADMITTED one —
     * matching the meaning `latest_version` already gives the same relation one level up, in
     * Portal\PackageController::index().
     */
    private function licenceNoteFor(Package $package, VersionBounds $bounds): ?PortalLicenceNote
    {
        if ($package->type === PackageType::Docker || $bounds->isUnlimited()) {
            return null;
        }

        $newest = $package->versions->first();

        if ($newest === null) {
            return null;
        }

        /** @var PackageVersion|null $highestPermitted */
        $highestPermitted = $package->versions->first(
            fn (PackageVersion $v): bool => $this->entitlement->permits($bounds, $package->type, $v->version)
        );

        if ($highestPermitted === null) {
            return new PortalLicenceNote(highest_permitted: null, withheld: true);
        }

        return new PortalLicenceNote(
            highest_permitted: $highestPermitted->version_pretty,
            withheld: $highestPermitted->isNot($newest),
        );
    }
}
