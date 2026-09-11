<?php

namespace App\Services\Package;

use App\Enums\PackageType;
use App\Http\Controllers\Concerns\ScopesToAdministeredOrgs;
use App\Models\Group;
use App\Models\Package;
use App\Support\Licence\Pep440Version;
use App\Support\Licence\VersionBounds;
use Carbon\CarbonInterface;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Illuminate\Validation\ValidationException;
use UnexpectedValueException;

/**
 * The single writer of `group_package.available_until`, `version_min` and `version_max` —
 * see {@see Group::assignedPackages()}'s docblock, which names this class as
 * the one place any of the three columns may be written from. Extracted out of
 * `Admin\GroupController::updateAssignment()`, which used to be the sole writer of
 * `available_until` alone and now delegates to this instead, so the invariant stays
 * literally true with two call sites rather than becoming false the moment a second one
 * (Admin\PackageAssignmentController, the package page's "Freigaben" surface) appears.
 *
 * ROUTING A WRITE THROUGH THIS CLASS IS WHAT MAKES IT SAFE, not merely convenient — every
 * one of `write()`, `assign()` and `revoke()` asks, ITSELF, in this order:
 *
 *   1. does the caller administer the TARGET GROUP's organization at all
 *      ({@see ScopesToAdministeredOrgs::assertAdministersGroupInScope()});
 *   2. does the caller administer the OWNING organization of a shared package, or own the
 *      package otherwise ({@see assertMayTouchAssignment()});
 *   3. (assign() only) is the package reachable into that group at all — own-org or shared
 *      ({@see ScopesToAdministeredOrgs::assertCanAttachPackages()});
 *   4. does the resulting assignment shadow, or get shadowed by, a same-named package of
 *      the other kind ({@see SharedAssignment::assertAssignable()});
 *   5. (write()/assign() only) are the bounds actually being persisted — the EFFECTIVE,
 *      post-merge pair, not merely what one caller happened to submit — syntactically
 *      valid for the package's type, ordered, and absent for a Docker package
 *      ({@see assertValidBounds()}).
 *
 * A caller (a controller, a future job, a console command) that reaches these three
 * methods without separately re-deriving any of the above gets the full guarantee for
 * free; one that ALSO asks question 1 itself (as `GroupController::updateAssignment()`
 * does, for its own 404-before-403 ordering) is simply asking it twice, harmlessly.
 *
 * `use`s {@see ScopesToAdministeredOrgs} — a controller concern, reached into
 * deliberately rather than restated — for guards 1, 2 and 3 above.
 */
final class AssignmentWriter
{
    use ScopesToAdministeredOrgs;

    public function __construct(private readonly SharedAssignment $sharedAssignment) {}

    /**
     * Writes available_until + bounds for ONE assignment. Assumes the pivot row already
     * exists — a caller writing against one that does not gets a silent no-op from
     * `updateExistingPivot()`, exactly as before extraction; the 404-for-an-unassigned-
     * package distinction is the calling controller's to make (see
     * `GroupController::updateAssignment()`), not this method's, so that a request naming
     * a package this registry never carried answers 404 rather than leaking, by the
     * choice of status code, whether the package is shared.
     *
     * `$bounds` is the EFFECTIVE pair the caller intends to persist — already merged with
     * whatever is currently stored for any side the caller's own request left unnamed.
     * Validating anything earlier than this (e.g. only the fields one HTTP request
     * happened to submit) cannot see that merge and can wave through an impossible window:
     * see AssignmentBoundsRequest's docblock for the incident this replaced.
     */
    public function write(Group $group, Package $package, ?CarbonInterface $availableUntil, VersionBounds $bounds): void
    {
        $this->assertAdministersGroupInScope($group);
        $this->assertMayTouchAssignment($package);
        $this->assertValidBounds($package->type, $bounds);
        $this->sharedAssignment->assertAssignable($group, [(string) $package->getKey()]);

        $group->packages()->updateExistingPivot($package->getKey(), [
            'available_until' => $availableUntil,
            'version_min' => $bounds->min,
            'version_max' => $bounds->max,
        ]);
    }

    /**
     * Creates the pivot row for a brand new assignment, with period and bounds set at
     * grant time — the package page's "Registry freigeben" picker. Same guards as
     * write(), plus the reachability one a FRESH row still has to answer and an existing
     * one already passed the first time it was written:
     * {@see ScopesToAdministeredOrgs::assertCanAttachPackages()}, own-org or shared.
     *
     * `syncWithoutDetaching()`, not `attach()`: re-assigning a package the registry
     * already carries updates its pivot attributes rather than throwing on a duplicate
     * key, so `assign()` doubles as "re-grant with new bounds" for a still-assigned
     * package — the same tolerance write() has for re-dating.
     */
    public function assign(Group $group, Package $package, ?CarbonInterface $availableUntil, VersionBounds $bounds): void
    {
        $this->assertAdministersGroupInScope($group);
        $this->assertCanAttachPackages([(string) $package->getKey()], $group->organization_id);
        $this->assertMayTouchAssignment($package);
        $this->assertValidBounds($package->type, $bounds);
        $this->sharedAssignment->assertAssignable($group, [(string) $package->getKey()]);

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
        $this->assertAdministersGroupInScope($group);
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
     *
     * NEITHER of these two asks whether the caller may touch the TARGET GROUP at all — a
     * shared package's owner administers it everywhere it might be shared, which says
     * nothing about whether they administer any GIVEN customer's registry. That is
     * {@see ScopesToAdministeredOrgs::assertAdministersGroupInScope()}'s question, asked
     * separately by every public method above.
     */
    private function assertMayTouchAssignment(Package $package): void
    {
        if ($package->shared) {
            $this->assertMayEditSharedAssignment($package);

            return;
        }

        $this->assertCanTouchPackage($package);
    }

    /**
     * Type-aware syntax and ordering, run on the bounds actually about to be persisted —
     * never on a raw request in isolation, which is what let an impossible window through
     * before this lived here (a stored `version_min` surviving an update that only named
     * `version_max`, ending up above it). Both admin surfaces funnel through write() and
     * assign(), so this is the one place either can wave an invalid pair through.
     *
     * Docker packages have no notion of a bounded version at all — see
     * `VersionEntitlement::permits()`'s guard — so any non-unlimited pair is refused
     * outright rather than run through a comparator that would compare image tags as if
     * they were versions.
     */
    private function assertValidBounds(PackageType $type, VersionBounds $bounds): void
    {
        if ($bounds->isUnlimited()) {
            return;
        }

        if ($type === PackageType::Docker) {
            throw ValidationException::withMessages([
                'version_min' => 'Für Docker-Pakete gibt es keine Versionsgrenzen.',
            ]);
        }

        if ($bounds->min !== null && ! $this->isValidBoundSyntax($type, $bounds->min)) {
            throw ValidationException::withMessages(['version_min' => 'Diese Version ist syntaktisch ungültig.']);
        }

        if ($bounds->max !== null && ! $this->isValidBoundSyntax($type, $bounds->max)) {
            throw ValidationException::withMessages(['version_max' => 'Diese Version ist syntaktisch ungültig.']);
        }

        if ($bounds->min !== null && $bounds->max !== null
            && ! $this->isOrdered($type, $bounds->min, $bounds->max)) {
            throw ValidationException::withMessages([
                'version_min' => 'Die Untergrenze muss kleiner als die Obergrenze sein.',
            ]);
        }
    }

    /**
     * Composer and npm share `composer/semver`'s parser; PyPI cannot reuse it — see
     * {@see Pep440Version}'s docblock for why — and goes through that instead.
     * `VersionParser::normalize()` throws on anything it cannot parse rather than
     * returning a sentinel, so the syntax check is the catch.
     */
    private function isValidBoundSyntax(PackageType $type, string $version): bool
    {
        if ($type === PackageType::Python) {
            return Pep440Version::parse($version) !== null;
        }

        try {
            (new VersionParser)->normalize($version);

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Both bounds already passed {@see isValidBoundSyntax()} by the time this runs, so
     * PyPI's `parse()` cannot return null here.
     */
    private function isOrdered(PackageType $type, string $min, string $max): bool
    {
        if ($type === PackageType::Python) {
            /** @var Pep440Version $parsedMin */
            $parsedMin = Pep440Version::parse($min);
            /** @var Pep440Version $parsedMax */
            $parsedMax = Pep440Version::parse($max);

            return $parsedMin->compareTo($parsedMax) < 0;
        }

        return Comparator::lessThan($min, $max);
    }
}
