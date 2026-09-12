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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;
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
     * How long an offending value's "already logged" fact is remembered before the same
     * value is allowed to log again.
     *
     * Not process-lifetime: a PHP-FPM worker survives many requests, so a plain in-memory
     * "logged once" flag really means "logged once per worker, for however long that
     * worker happens to live" — an operator fixes the underlying row, the problem recurs
     * later (feed regression, retry, a different package reusing the same string), and no
     * second warning appears until the worker recycles. Cache::add() below makes the
     * dedupe both time-boxed AND shared across workers, which is what "logged once" is
     * actually meant to mean.
     */
    private const DEDUPE_WINDOW_SECONDS = 3600;

    /**
     * Records that `$value` has just been logged for `$namespace`, returning whether this
     * call is the one that should actually emit the log line.
     *
     * `Cache::add()` is the whole dedupe in one atomic call: it writes the key and returns
     * true only if the key did not already exist, so two concurrent callers (or two calls
     * within the same request) can never both be told "you're first". The raw value is
     * hashed rather than interpolated into the key because a version or bound string can
     * contain characters (whitespace, `:`, control bytes from a malformed feed) a cache key
     * should not carry, and `$namespace` keeps a version-string dedupe and a bound-string
     * dedupe — and the PyPI and Composer/npm variants of each — from colliding with each
     * other, or with anything else in the shared cache, even when the raw value is
     * identical across them.
     *
     * Deliberately fails OPEN on a cache-store exception (Redis refused, DB connection
     * lost, …) — the opposite of every other fail-closed rule in this class, and
     * intentionally so: this mechanism is best-effort observability bolted onto a decision
     * that has already been made (permits() has already computed its refusal by the time
     * either caller reaches this method), never a gate on it. Letting the exception escape
     * would turn a cache outage into an uncaught 500 on every request for an already-known-
     * bad version, where the caller previously refused cleanly — the dedupe is worth losing
     * for that one call, the clean refusal is not.
     */
    private function shouldLogOnce(string $namespace, string $value): bool
    {
        $key = sprintf('licence-entitlement:unparseable:%s:%s', $namespace, hash('sha256', $value));

        try {
            return Cache::add($key, true, self::DEDUPE_WINDOW_SECONDS);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * The bounds a single assignment grants, read straight off its pivot row — or `null` if
     * no such row exists for this (group, package) pair.
     *
     * Deliberately reads `Group::packages()`, not `assignedPackages()`: whether the
     * assignment is still in force is a separate question the caller has already answered
     * (or is answering via `windowsForOrganization()`), and restating the expiry predicate
     * here would risk the two disagreeing about which row counts.
     *
     * `null` on a missing row, rather than `VersionBounds::unlimited()`, is what makes this
     * method fail CLOSED: nothing holds a transaction across a caller's own "resolve" read
     * and this one, so `AssignmentWriter::revoke()` (which detaches with no transaction) can
     * land in between and leave this query with no row to find, for a request that had
     * every reason to believe an assignment existed a moment earlier. Every caller must
     * treat `null` as "not available to this registry" — the same answer it already gives
     * when resolution itself fails — never as license to serve anything. This is
     * `windowsForOrganization()`'s twin for the single-group path: that method states the
     * identical rule for the org-wide union through `VersionWindows::isUnlimited()`'s
     * docblock (an empty window list is not unlimited, for the same race, stated there
     * first) — the two must keep agreeing on which shape of "nothing found" is refused
     * versus which shape of "found, no bounds" is unlimited.
     */
    public function boundsFor(Group $group, Package $package): ?VersionBounds
    {
        $assignment = $group->packages()
            ->where('packages.id', $package->getKey())
            ->first();

        if ($assignment === null) {
            return null;
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
     * `Pep440Version` instead, which also reads a local version segment (`1.0+cu118`) and
     * the implicit post shorthand (`1.0-1`) — see that class's docblock. A version PEP 440
     * still cannot parse with confidence cannot be placed against a bound honestly, so a
     * bounded licence fails closed and refuses it; the refusal is logged once per version so
     * an upstream feed full of unparseable versions does not flood the log. The same
     * fail-closed rule applies the other way round: if the bound
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
        if (! $this->shouldLogOnce('pep440-version', $version)) {
            return;
        }

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
        if (! $this->shouldLogOnce('pep440-bound', $bound)) {
            return;
        }

        Log::warning('PyPI licence bound could not be parsed as PEP 440; refusing every version under it.', [
            'bound' => $bound,
        ]);
    }

    /** Composer/npm counterpart of {@see logUnparseableVersionOnce()} — see permitsSemver(). */
    private function logUnparseableSemverVersionOnce(string $version): void
    {
        if (! $this->shouldLogOnce('semver-version', $version)) {
            return;
        }

        Log::warning('Composer/npm version could not be normalized; refusing it under a bounded licence.', [
            'version' => $version,
        ]);
    }

    /** Composer/npm counterpart of {@see logUnparseableBoundOnce()} — see permitsSemver(). */
    private function logUnparseableSemverBoundOnce(string $bound): void
    {
        if (! $this->shouldLogOnce('semver-bound', $bound)) {
            return;
        }

        Log::warning('Composer/npm licence bound could not be normalized; refusing every version under it.', [
            'bound' => $bound,
        ]);
    }
}
