<?php

namespace App\Services\Licence;

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Support\Licence\Pep440Version;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
use Closure;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use LogicException;
use UnexpectedValueException;

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
    /** Docker has no notion of a licence-bounded version at all — see permits()'s guard. */
    private const DOCKER_BOUNDS_UNSUPPORTED = 'Version bounds are not supported for Docker packages.';

    /**
     * Versions already logged as unparseable, so a package with many unparseable releases
     * does not flood the log once per request. Keyed by the raw version string; process-
     * lifetime only, which is what "once" can mean without a place to persist the fact.
     *
     * @var array<string, true>
     */
    private static array $loggedUnparseableVersions = [];

    /**
     * Bound strings (the pivot's own `version_min`/`version_max`) already logged as
     * unparseable — same "once per process" reasoning as $loggedUnparseableVersions, keyed
     * separately because a bad bound and a bad served version are different operator
     * problems: one is a row to fix, the other is upstream data to tolerate.
     *
     * @var array<string, true>
     */
    private static array $loggedUnparseableBounds = [];

    /**
     * Same "once per process" dedupe as {@see $loggedUnparseableVersions}, for a Composer
     * or npm version `VersionParser::normalize()` cannot parse — kept separate from that
     * array (rather than shared with Python) because the two ecosystems log different
     * text for the same fact, and a Composer and a Python version that happened to share
     * one raw string must not silently dedupe against each other's log line.
     *
     * @var array<string, true>
     */
    private static array $loggedUnparseableSemverVersions = [];

    /**
     * Same reasoning as {@see $loggedUnparseableSemverVersions}, for a Composer/npm
     * `version_min`/`version_max` bound that cannot be normalized.
     *
     * @var array<string, true>
     */
    private static array $loggedUnparseableSemverBounds = [];

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
     * ONE query: walks the pivot from the Package side (`Package::groups()`, which already
     * carries the version_min/version_max `withPivot`), scoped to this organization's groups
     * and filtered by `Group::notExpiredAssignmentPredicate()` — the exact closure
     * `Group::assignedPackages()` applies from the other side of the same pivot table, so
     * this method and the registry's own serving decision can never disagree about which
     * assignment row counts. This sits on the `/o/{orgSlug}` metadata path, the same hot
     * path `RegistryAccessService::organizationPackagesQuery()` is pinned to one query for —
     * a query per group here would reintroduce exactly the N+1 that method was built to
     * avoid.
     */
    public function windowsForOrganization(Organization $org, Package $package): VersionWindows
    {
        // Same generic-over-Model predicate assignedPackages() applies from the Group side;
        // this call reaches it from the Package side, so it asserts the other of the two
        // concrete instantiations — see that method's comment for why.
        /** @var Closure(Builder<Group>): Builder<Group> $predicate */
        $predicate = Group::notExpiredAssignmentPredicate();

        $windows = $package->groups()
            ->where('groups.organization_id', $org->id)
            ->where($predicate)
            ->get()
            ->map(fn (Group $group): VersionBounds => VersionBounds::fromPivot(
                $group->pivot->version_min,
                $group->pivot->version_max,
            ))
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
     * not flood the log. The same fail-closed rule applies the other way round: if the bound
     * itself (the pivot's own `version_min`/`version_max`) cannot be parsed — {@see
     * \App\Services\Package\AssignmentWriter::assertValidBounds()} now refuses a
     * syntactically invalid bound at write time, but that guard cannot reach a row written
     * before it existed, or one written by anything other than AssignmentWriter — every
     * version is refused rather than the unparseable side being silently treated as open,
     * logged once per bound value so an operator can find the offending row.
     *
     * Docker packages carry no version bounds concept at all (there is no licence-scoped
     * assignment UI for them and none is planned), so a Docker type is refused outright
     * rather than being run through the semver comparator, which would compare image tags
     * as if they were semver and could silently produce a meaningless answer.
     */
    public function permits(VersionBounds $bounds, PackageType $type, string $version): bool
    {
        if ($type === PackageType::Docker) {
            throw new LogicException(self::DOCKER_BOUNDS_UNSUPPORTED);
        }

        if ($bounds->isUnlimited()) {
            return true;
        }

        return $type === PackageType::Python
            ? $this->permitsPython($bounds, $version)
            : $this->permitsSemver($bounds, $version);
    }

    /**
     * Whether a version is admitted by ANY window — never a hull of them.
     *
     * Rejects `PackageType::Docker` up front, the same as permits(): checked here too,
     * rather than left to be reached only through the per-window call to permits(), because
     * an all-unlimited VersionWindows short-circuits below without ever calling permits() at
     * all, which would let a Docker call through silently.
     */
    public function permitsAny(VersionWindows $windows, PackageType $type, string $version): bool
    {
        if ($type === PackageType::Docker) {
            throw new LogicException(self::DOCKER_BOUNDS_UNSUPPORTED);
        }

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

    /**
     * `Comparator::*` is a thin wrapper over raw `version_compare()` — it does NOT
     * normalize either operand. Comparing an un-normalized pre-release against a short
     * bound compares them as plain strings rather than composer-semantically: a customer
     * licenced `min=2.0` would be served `2.0.0-beta1` (`version_compare('2.0.0-beta1',
     * '2.0', '>=')` is true, even though `2.0.0-beta1 < 2.0.0` composer-semantically), and
     * `3.0.0-beta1` would be wrongly withheld under `max=3.0` despite sitting inside the
     * window. Normalizing BOTH the served version and the bound with the same
     * `VersionParser::normalize()` composer itself uses (the one `AssignmentWriter`
     * already validates bound syntax with) fixes both directions at once, and handles a
     * `v`-prefixed bound (`v2.0`) identically to its bare form.
     *
     * A side that cannot be normalized — a malformed served version, or a bound written
     * before write-time validation existed — fails closed exactly as `permitsPython()`
     * does for its own unparseable version/bound: refused, logged once per value so a
     * feed full of one bad string does not flood the log.
     */
    private function permitsSemver(VersionBounds $bounds, string $version): bool
    {
        $normalizedVersion = $this->normalizeSemver($version);

        if ($normalizedVersion === null) {
            $this->logUnparseableSemverVersionOnce($version);

            return false;
        }

        if ($bounds->min !== null) {
            $normalizedMin = $this->normalizeSemver($bounds->min);

            if ($normalizedMin === null) {
                $this->logUnparseableSemverBoundOnce($bounds->min);

                return false;
            }

            if (! Comparator::greaterThanOrEqualTo($normalizedVersion, $normalizedMin)) {
                return false;
            }
        }

        if ($bounds->max !== null) {
            $normalizedMax = $this->normalizeSemver($bounds->max);

            if ($normalizedMax === null) {
                $this->logUnparseableSemverBoundOnce($bounds->max);

                return false;
            }

            if (! Comparator::lessThan($normalizedVersion, $normalizedMax)) {
                return false;
            }
        }

        return true;
    }

    /** `VersionParser::normalize()` throws on anything it cannot parse rather than returning a sentinel. */
    private function normalizeSemver(string $version): ?string
    {
        try {
            return (new VersionParser)->normalize($version);
        } catch (UnexpectedValueException) {
            return null;
        }
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

            if ($min === null) {
                $this->logUnparseableBoundOnce($bounds->min);

                return false;
            }

            if ($parsed->compareTo($min) < 0) {
                return false;
            }
        }

        if ($bounds->max !== null) {
            $max = Pep440Version::parse($bounds->max);

            if ($max === null) {
                $this->logUnparseableBoundOnce($bounds->max);

                return false;
            }

            if ($parsed->compareTo($max) >= 0) {
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

    /**
     * Logs a licence bound (`version_min` or `version_max`) that PEP 440 cannot parse —
     * distinct from logUnparseableVersionOnce() because this names a bad row in
     * `group_package` rather than a version served from a package's own release history, and
     * an operator needs the bound's value, not a served version, to go find and fix it.
     */
    private function logUnparseableBoundOnce(string $bound): void
    {
        if (isset(self::$loggedUnparseableBounds[$bound])) {
            return;
        }

        self::$loggedUnparseableBounds[$bound] = true;

        Log::warning('PyPI licence bound could not be parsed as PEP 440; refusing every version under it.', [
            'bound' => $bound,
        ]);
    }

    /** Composer/npm counterpart of {@see logUnparseableVersionOnce()} — see permitsSemver(). */
    private function logUnparseableSemverVersionOnce(string $version): void
    {
        if (isset(self::$loggedUnparseableSemverVersions[$version])) {
            return;
        }

        self::$loggedUnparseableSemverVersions[$version] = true;

        Log::warning('Composer/npm version could not be normalized; refusing it under a bounded licence.', [
            'version' => $version,
        ]);
    }

    /** Composer/npm counterpart of {@see logUnparseableBoundOnce()} — see permitsSemver(). */
    private function logUnparseableSemverBoundOnce(string $bound): void
    {
        if (isset(self::$loggedUnparseableSemverBounds[$bound])) {
            return;
        }

        self::$loggedUnparseableSemverBounds[$bound] = true;

        Log::warning('Composer/npm licence bound could not be normalized; refusing every version under it.', [
            'bound' => $bound,
        ]);
    }
}
