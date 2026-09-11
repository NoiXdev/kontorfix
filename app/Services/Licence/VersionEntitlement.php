<?php

namespace App\Services\Licence;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Support\Licence\Pep440Version;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
use Composer\Semver\Comparator;
use Illuminate\Support\Facades\Log;

/**
 * The single rule that decides which versions a licence admits — every serve-time site
 * (Composer metadata, npm tarball listing, the PyPI simple index) asks this service rather
 * than comparing version strings itself, so the three ecosystems can never quietly disagree
 * about what "in bounds" means.
 *
 * A bound is inclusive at `min` and exclusive at `max` — `[min, max)` — matching how a
 * licence is sold ("from 2.0 up to, but not including, 3.0").
 */
final class VersionEntitlement
{
    /**
     * Versions already logged as unparseable, so a package with many unparseable releases
     * does not flood the log once per request. Keyed by the raw version string; process-
     * lifetime only, which is what "once" can mean without a place to persist the fact.
     *
     * @var array<string, true>
     */
    private static array $loggedUnparseableVersions = [];

    /**
     * The bounds a single assignment grants, read straight off its pivot row.
     *
     * Deliberately reads `Group::packages()`, not `assignedPackages()`: whether the
     * assignment is still in force is a separate question the caller has already answered
     * (or is answering via `windowsForOrganization()`), and restating the expiry predicate
     * here would risk the two disagreeing about which row counts.
     */
    public function boundsFor(Group $group, Package $package): VersionBounds
    {
        $assignment = $group->packages()
            ->where('packages.id', $package->getKey())
            ->first();

        if ($assignment === null) {
            return VersionBounds::unlimited();
        }

        return VersionBounds::fromPivot($assignment->pivot->version_min, $assignment->pivot->version_max);
    }

    /**
     * One window per UNEXPIRED assignment of this package across the organization's
     * registries — not a hull. `[2.0,3.0)` from one registry and `[4.0,5.0)` from another
     * permits 2.x and 4.x but must still refuse 3.x, which a merged `[2.0,5.0)` range would
     * wrongly admit.
     *
     * Goes through `Group::assignedPackages()` for the expiry check rather than restating
     * it: that relation is the one statement of "is this assignment still in force" shared
     * with the registry's own serving decision, so an expired row here is exactly an
     * expired row there.
     */
    public function windowsForOrganization(Organization $org, Package $package): VersionWindows
    {
        $windows = $org->groups()
            ->get()
            ->flatMap(fn (Group $group) => $group->assignedPackages()
                ->where('packages.id', $package->getKey())
                ->get())
            ->map(fn (Package $assigned): VersionBounds => VersionBounds::fromPivot(
                $assigned->pivot->version_min,
                $assigned->pivot->version_max,
            ))
            ->values()
            ->all();

        return new VersionWindows($windows);
    }

    /**
     * Whether a single version falls within a single window.
     *
     * Unlimited bounds admit everything and skip parsing entirely — an unparseable Python
     * version under an unlimited licence is still served normally, because no filtering
     * happens at all.
     *
     * Composer and npm share one semver comparator; PyPI's PEP 440 ordering (epochs,
     * pre/post/dev segments) has no equivalent in `composer/semver`, so it goes through
     * `Pep440Version` instead. A version PEP 440 cannot parse with confidence — including a
     * local version (`1.0+cu118`) or the implicit post shorthand (`1.0-1`) — cannot be
     * placed against a bound honestly, so a bounded licence fails closed and refuses it;
     * the refusal is logged once per version so an upstream feed full of local versions does
     * not flood the log.
     */
    public function permits(VersionBounds $bounds, PackageType $type, string $version): bool
    {
        if ($bounds->isUnlimited()) {
            return true;
        }

        return $type === PackageType::Python
            ? $this->permitsPython($bounds, $version)
            : $this->permitsSemver($bounds, $version);
    }

    /** Whether a version is admitted by ANY window — never a hull of them. */
    public function permitsAny(VersionWindows $windows, PackageType $type, string $version): bool
    {
        if ($windows->isUnlimited()) {
            return true;
        }

        foreach ($windows->windows as $bounds) {
            if ($this->permits($bounds, $type, $version)) {
                return true;
            }
        }

        return false;
    }

    private function permitsSemver(VersionBounds $bounds, string $version): bool
    {
        if ($bounds->min !== null && ! Comparator::greaterThanOrEqualTo($version, $bounds->min)) {
            return false;
        }

        if ($bounds->max !== null && ! Comparator::lessThan($version, $bounds->max)) {
            return false;
        }

        return true;
    }

    private function permitsPython(VersionBounds $bounds, string $version): bool
    {
        $parsed = Pep440Version::parse($version);

        if ($parsed === null) {
            $this->logUnparseableVersionOnce($version);

            return false;
        }

        if ($bounds->min !== null) {
            $min = Pep440Version::parse($bounds->min);
            if ($min !== null && $parsed->compareTo($min) < 0) {
                return false;
            }
        }

        if ($bounds->max !== null) {
            $max = Pep440Version::parse($bounds->max);
            if ($max !== null && $parsed->compareTo($max) >= 0) {
                return false;
            }
        }

        return true;
    }

    private function logUnparseableVersionOnce(string $version): void
    {
        if (isset(self::$loggedUnparseableVersions[$version])) {
            return;
        }

        self::$loggedUnparseableVersions[$version] = true;

        Log::warning('PyPI version could not be parsed as PEP 440; refusing it under a bounded licence.', [
            'version' => $version,
        ]);
    }
}
