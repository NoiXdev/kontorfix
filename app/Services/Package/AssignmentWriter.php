<?php

namespace App\Services\Package;

use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Models\Group;
use App\Models\Package;
use App\Support\Licence\VersionBounds;
use Carbon\CarbonInterface;

/**
 * The single writer of `group_package.available_until`, `version_min` and `version_max` —
 * see {@see Group::assignedPackages()}'s docblock, which names this class as
 * the one place any of the three columns may be written from. Extracted out of
 * `Admin\GroupController::updateAssignment()`, which used to be the sole writer of
 * `available_until` alone and now delegates to this instead, so the invariant stays
 * literally true with two call sites rather than becoming false the moment a second one
 * (Admin\PackageAssignmentController, the package page's "Freigaben" surface) appears.
 *
 * `use`s {@see ScopesToAdministeredOrgs} — a controller concern, reached into
 * deliberately rather than restated — for the two guards every method here carries:
 * {@see assertMayTouchAssignment()} composes both of them.
 */
final class AssignmentWriter
{
    use ScopesToAdministeredOrgs;

    /**
     * Writes available_until + bounds for ONE assignment. Runs the same guards
     * `updateAssignment()` ran. Assumes the pivot row already exists — a caller writing
     * against one that does not gets a silent no-op from `updateExistingPivot()`, exactly
     * as before extraction; the 404-for-an-unassigned-package distinction is the calling
     * controller's to make (see `GroupController::updateAssignment()`), not this method's,
     * so that a request naming a package this registry never carried answers 404 rather
     * than leaking, by the choice of status code, whether the package is shared.
     *
     * `$bounds` is validated syntax already — see AssignmentBoundsRequest, which both admin
     * surfaces share so they can never validate bounds differently.
     */
    public function write(Group $group, Package $package, ?CarbonInterface $availableUntil, VersionBounds $bounds): void
    {
        $this->assertMayTouchAssignment($package);

        $group->packages()->updateExistingPivot($package->getKey(), [
            'available_until' => $availableUntil,
            'version_min' => $bounds->min,
            'version_max' => $bounds->max,
        ]);
    }

    /**
     * Creates the pivot row for a brand new assignment, with period and bounds set at
     * grant time — the package page's "Registry freigeben" picker. Same authorization
     * question as write(), plus the reachability one a FRESH row still has to answer and
     * an existing one already passed the first time it was written:
     * {@see ScopesToAdministeredOrgs::assertCanAttachPackages()}, own-org or shared.
     *
     * `syncWithoutDetaching()`, not `attach()`: re-assigning a package the registry
     * already carries updates its pivot attributes rather than throwing on a duplicate
     * key, so `assign()` doubles as "re-grant with new bounds" for a still-assigned
     * package — the same tolerance write() has for re-dating.
     */
    public function assign(Group $group, Package $package, ?CarbonInterface $availableUntil, VersionBounds $bounds): void
    {
        $this->assertCanAttachPackages([(string) $package->getKey()], $group->organization_id);
        $this->assertMayTouchAssignment($package);

        $group->packages()->syncWithoutDetaching([
            $package->getKey() => [
                'available_until' => $availableUntil,
                'version_min' => $bounds->min,
                'version_max' => $bounds->max,
            ],
        ]);
    }

    /**
     * Ends an assignment outright — the pivot row disappears, rather than merely
     * expiring. Same authorization question as write() and assign(): for a single named
     * package, "may this caller change who has it" and "may this caller take it away
     * entirely" are the same question asked of the same row.
     */
    public function revoke(Group $group, Package $package): void
    {
        $this->assertMayTouchAssignment($package);

        $group->packages()->detach($package->getKey());
    }

    /**
     * WHO may create, edit or end this ONE package's assignment — the disjunction the
     * design spec states in one sentence: administering the package's OWNING organization
     * for a shared package, and {@see ScopesToAdministeredOrgs::assertCanTouchPackage()}
     * (ownership within the caller's ACTIVE CONSOLE SCOPE) for anything else.
     *
     * The two guards are not both asked of the same package, and that is deliberate
     * rather than an oversight: {@see assertMayEditSharedAssignment()} reads
     * `administeredOrganizationIds()` — the caller's full administered set, regardless of
     * where the console's scope switch currently points — while assertCanTouchPackage()
     * reads the narrower ACTIVE SCOPE. Asking both of a shared package would make a
     * console scoped down to one organization unable to manage that organization's OWN
     * shared packages from a customer's registry page, even though the caller plainly
     * administers the owner. A non-shared package never reaches
     * assertMayEditSharedAssignment() in the first place — it answers trivially (`[]`) for
     * one, by construction — so calling assertCanTouchPackage() for that side is the only
     * one of the two actually asking anything.
     */
    private function assertMayTouchAssignment(Package $package): void
    {
        if ($package->shared) {
            $this->assertMayEditSharedAssignment($package);

            return;
        }

        $this->assertCanTouchPackage($package);
    }
}
