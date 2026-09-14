<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->svc = app(VersionEntitlement::class);
});

describe('permits', function () {
    describe('Composer', function () {
        it('permits a version inside the bounds', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '2.5.0'))->toBeTrue();
        });

        it('permits a version exactly at min, inclusive', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '2.0.0'))->toBeTrue();
        });

        it('refuses a version exactly at max, exclusive', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '3.0.0'))->toBeFalse();
        });

        it('refuses a version below min', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '1.9.0'))->toBeFalse();
        });

        it('refuses a version above max', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '3.1.0'))->toBeFalse();
        });

        it('permits everything at or above min when only min is set', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', null);

            expect($this->svc->permits($bounds, PackageType::Composer, '2.0.0'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Composer, '99.0.0'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Composer, '1.9.9'))->toBeFalse();
        });

        it('permits everything below max when only max is set', function () {
            $bounds = VersionBounds::fromPivot(null, '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '0.0.1'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Composer, '2.9.9'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Composer, '3.0.0'))->toBeFalse();
        });

        it('permits everything when unlimited', function () {
            expect($this->svc->permits(VersionBounds::unlimited(), PackageType::Composer, '999.999.999'))->toBeTrue();
        });

        // Comparator::* does not normalize either side — it is raw version_compare(). A
        // pre-release compared against a short bound sorts on string length instead of on
        // composer-semantic precedence, which either lets a pre-release through below the
        // floor or withholds one that is genuinely inside the window. Normalizing both
        // sides first (VersionParser::normalize()) is what the spec's "on the normalized
        // version" phrase requires.
        it('refuses a pre-release that composer-semantically sorts below an unnormalized min', function () {
            $bounds = VersionBounds::fromPivot('2.0', '3.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '2.0.0-beta1'))->toBeFalse();
        });

        it('permits a pre-release that composer-semantically sorts inside the window, below an unnormalized max', function () {
            $bounds = VersionBounds::fromPivot('2.0', '3.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '3.0.0-beta1'))->toBeTrue();
        });

        it('normalizes a v-prefixed bound the same as its bare equivalent', function () {
            $prefixed = VersionBounds::fromPivot('v2.0', '3.0');
            $bare = VersionBounds::fromPivot('2.0', '3.0');

            expect($this->svc->permits($prefixed, PackageType::Composer, '2.0.0-beta1'))
                ->toBe($this->svc->permits($bare, PackageType::Composer, '2.0.0-beta1'))
                ->and($this->svc->permits($prefixed, PackageType::Composer, '2.5.0'))
                ->toBe($this->svc->permits($bare, PackageType::Composer, '2.5.0'));
        });

        it('fails closed and logs once when a bound cannot be normalized', function () {
            Log::shouldReceive('warning')->once();

            $bounds = VersionBounds::fromPivot('not-a-version', '3.0');

            expect($this->svc->permits($bounds, PackageType::Composer, '2.5.0'))->toBeFalse();
        });

        // The semver-namespace counterpart of the PEP 440 namespacing pin below: an
        // unnormalizable Composer version and an unnormalizable Composer bound that happen
        // to share one raw string must log separately rather than dedupe against each
        // other — proving logUnparseableSemverVersionOnce() and
        // logUnparseableSemverBoundOnce() are namespaced apart, not just inspected as such.
        it('does not let an unnormalizable composer version and an unnormalizable composer bound sharing the same string suppress each other', function () {
            Log::shouldReceive('warning')->twice();

            $sharedString = 'shared-unnormalizable-string';

            // First call: the bad value is the served version.
            $this->svc->permits(VersionBounds::fromPivot('2.0.0', '3.0.0'), PackageType::Composer, $sharedString);

            // Second call: the same string is now the bad value on the bound side, of a
            // version that itself normalizes fine.
            $this->svc->permits(VersionBounds::fromPivot($sharedString, '3.0.0'), PackageType::Composer, '2.5.0');
        });
    });

    describe('Npm', function () {
        it('permits a version inside the bounds', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Npm, '2.5.0'))->toBeTrue();
        });

        it('permits a version exactly at min, inclusive', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Npm, '2.0.0'))->toBeTrue();
        });

        it('refuses a version exactly at max, exclusive', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Npm, '3.0.0'))->toBeFalse();
        });

        it('refuses a version below min', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Npm, '1.9.0'))->toBeFalse();
        });

        it('refuses a version above max', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Npm, '3.1.0'))->toBeFalse();
        });

        it('permits everything at or above min when only min is set', function () {
            $bounds = VersionBounds::fromPivot('2.0.0', null);

            expect($this->svc->permits($bounds, PackageType::Npm, '99.0.0'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Npm, '1.9.9'))->toBeFalse();
        });

        it('permits everything below max when only max is set', function () {
            $bounds = VersionBounds::fromPivot(null, '3.0.0');

            expect($this->svc->permits($bounds, PackageType::Npm, '0.0.1'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Npm, '3.0.0'))->toBeFalse();
        });

        it('permits everything when unlimited', function () {
            expect($this->svc->permits(VersionBounds::unlimited(), PackageType::Npm, '999.999.999'))->toBeTrue();
        });
    });

    describe('Python', function () {
        it('permits a version inside the bounds', function () {
            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '1.5'))->toBeTrue();
        });

        it('permits a version exactly at min, inclusive', function () {
            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '1.0'))->toBeTrue();
        });

        it('refuses a version exactly at max, exclusive', function () {
            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '2.0'))->toBeFalse();
        });

        it('refuses a version below min', function () {
            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '0.9'))->toBeFalse();
        });

        it('refuses a version above max', function () {
            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '2.1'))->toBeFalse();
        });

        it('permits everything at or above min when only min is set', function () {
            $bounds = VersionBounds::fromPivot('1.0', null);

            expect($this->svc->permits($bounds, PackageType::Python, '99.0'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Python, '0.9'))->toBeFalse();
        });

        it('permits everything below max when only max is set', function () {
            $bounds = VersionBounds::fromPivot(null, '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '0.0.1'))->toBeTrue()
                ->and($this->svc->permits($bounds, PackageType::Python, '2.0'))->toBeFalse();
        });

        it('permits everything when unlimited', function () {
            expect($this->svc->permits(VersionBounds::unlimited(), PackageType::Python, '999.0'))->toBeTrue();
        });

        it('refuses a pre-release that sorts below min, even though it shares the release segment', function () {
            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '1.0rc1'))->toBeFalse();
        });

        it('fails closed on an unparseable version when bounds are set', function () {
            Log::shouldReceive('warning')->once();

            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, 'nicht-eine-version'))->toBeFalse();
        });

        it('logs an unparseable version only once within the dedupe window', function () {
            Log::shouldReceive('warning')->once();

            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            // Cache::add() dedupes on a per-key basis, and the test cache store is fresh
            // per test (the whole application container is rebuilt in setUp()), so there
            // is no longer a cross-test leakage concern here — this string need not be
            // unique across the file, only within this test.
            //
            // Both calls must still refuse the version: whether the log fires is purely
            // an observability concern, never a factor in the fail-closed decision itself.
            expect($this->svc->permits($bounds, PackageType::Python, 'onlyonce-not-a-version'))->toBeFalse()
                ->and($this->svc->permits($bounds, PackageType::Python, 'onlyonce-not-a-version'))->toBeFalse();
        });

        it('logs an unparseable version again once the dedupe window has elapsed', function () {
            Log::shouldReceive('warning')->twice();

            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            $this->svc->permits($bounds, PackageType::Python, 'window-elapsed-not-a-version');

            $this->travel(61)->minutes();

            $this->svc->permits($bounds, PackageType::Python, 'window-elapsed-not-a-version');
        });

        it('logs two different unparseable versions separately, with no collision between them', function () {
            Log::shouldReceive('warning')->twice();

            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            $this->svc->permits($bounds, PackageType::Python, 'first-distinct-not-a-version');
            $this->svc->permits($bounds, PackageType::Python, 'second-distinct-not-a-version');
        });

        it('does not let an unparseable version and an unparseable bound sharing the same string suppress each other', function () {
            Log::shouldReceive('warning')->twice();

            $sharedString = 'shared-unparseable-string';

            // First call: the bad value is the served version.
            $this->svc->permits(VersionBounds::fromPivot('1.0', '2.0'), PackageType::Python, $sharedString);

            // Second call: the very same string is now the bad value on the OTHER side —
            // the min bound — of a version that itself parses fine. If the version-dedupe
            // and bound-dedupe keys were not namespaced separately, this would be wrongly
            // suppressed as "already logged".
            $this->svc->permits(VersionBounds::fromPivot($sharedString, '2.0'), PackageType::Python, '1.5');
        });

        it('serves an unparseable version normally when bounds are unlimited, doing no filtering and no logging', function () {
            Log::shouldReceive('warning')->never();

            expect($this->svc->permits(VersionBounds::unlimited(), PackageType::Python, 'nicht-eine-version'))->toBeTrue();
        });

        it('fails closed when the stored min bound itself cannot be parsed as PEP 440', function () {
            Log::shouldReceive('warning')->once();

            // An invalid row that write-path validation (a later task) will eventually
            // refuse to create — but nothing here should ever guess it means "no lower
            // bound" just because it could not be read.
            $bounds = VersionBounds::fromPivot('nicht-eine-min-version', '2.0');

            expect($this->svc->permits($bounds, PackageType::Python, '1.5'))->toBeFalse();
        });

        it('fails closed when the stored max bound itself cannot be parsed as PEP 440', function () {
            Log::shouldReceive('warning')->once();

            $bounds = VersionBounds::fromPivot('1.0', 'nicht-eine-max-version');

            expect($this->svc->permits($bounds, PackageType::Python, '1.5'))->toBeFalse();
        });

        // The customer-facing bug this branch fixes: a licence bounded to [2.0, 3.0) must
        // still serve a local-version wheel build. PEP 440 orders a local version equal to
        // its public version for bound purposes (packaging ranks it strictly above when
        // comparing two full versions, but a licence bound is a public-version comparison —
        // see Pep440Version::parse()'s docblock), so 2.1.0+cu118 sits inside [2.0, 3.0).
        it('permits a local version whose public version sits inside the bounds', function () {
            $bounds = VersionBounds::fromPivot('2.0', '3.0');

            expect($this->svc->permits($bounds, PackageType::Python, '2.1.0+cu118'))->toBeTrue();
        });

        // The fail-closed rule this branch must NOT weaken: a version PEP 440 genuinely
        // cannot read is still refused under a bounded licence, local-segment support or not.
        it('still fails closed on a genuinely unparseable version under bounds, even after local-version support', function () {
            Log::shouldReceive('warning')->once();

            $bounds = VersionBounds::fromPivot('2.0', '3.0');

            expect($this->svc->permits($bounds, PackageType::Python, 'not-a-real-version-at-all'))->toBeFalse();
        });
    });
});

describe('unparseable-value dedupe resilience', function () {
    // The dedupe is best-effort observability bolted onto a decision permits() has
    // already made — it must never be able to turn a cache-store outage into a broken
    // response. Forcing Cache::add() to throw proves shouldLogOnce() swallows the
    // exception rather than letting it escape permits() as an uncaught 500, but does NOT
    // discard it: the throwable gets its own warning line (naming the exception class and
    // message, so a genuine cache-layer bug stays visible instead of vanishing into an
    // ordinary "unparseable version" line), and the licence warning still fires as normal
    // — both lines, not one swallowed for the other.
    it('logs the cache failure AND the licence warning, and still refuses, when the cache store throws', function () {
        Cache::shouldReceive('add')->once()->andThrow(new RuntimeException('cache store unavailable'));

        Log::shouldReceive('warning')->once()->with(
            'Licence entitlement dedupe cache is unavailable; logging without deduplication.',
            Mockery::on(fn (array $context): bool => $context['exception'] === RuntimeException::class
                && $context['message'] === 'cache store unavailable'),
        );

        Log::shouldReceive('warning')->once()->with(
            'PyPI version could not be parsed as PEP 440; refusing it under a bounded licence.',
            ['version' => 'cache-outage-not-a-version'],
        );

        $bounds = VersionBounds::fromPivot('1.0', '2.0');

        expect($this->svc->permits($bounds, PackageType::Python, 'cache-outage-not-a-version'))->toBeFalse();
    });
});

describe('permits Docker guard', function () {
    it('throws for permits() rather than silently comparing image tags as semver', function () {
        $bounds = VersionBounds::fromPivot('1.0.0', '2.0.0');

        expect(fn () => $this->svc->permits($bounds, PackageType::Docker, '1.5.0'))
            ->toThrow(LogicException::class, 'Version bounds are not supported for Docker packages.');
    });

    it('throws for permits() even when the bounds are unlimited', function () {
        expect(fn () => $this->svc->permits(VersionBounds::unlimited(), PackageType::Docker, 'latest'))
            ->toThrow(LogicException::class);
    });

    it('throws for permitsAny() rather than silently comparing image tags as semver', function () {
        $windows = new VersionWindows([VersionBounds::fromPivot('1.0.0', '2.0.0')]);

        expect(fn () => $this->svc->permitsAny($windows, PackageType::Docker, '1.5.0'))
            ->toThrow(LogicException::class, 'Version bounds are not supported for Docker packages.');
    });

    it('throws for permitsAny() even when the windows are unlimited', function () {
        expect(fn () => $this->svc->permitsAny(VersionWindows::unlimited(), PackageType::Docker, 'latest'))
            ->toThrow(LogicException::class);
    });
});

describe('permitsAny', function () {
    it('permits a version admitted by any window and refuses one admitted by none', function () {
        $windows = new VersionWindows([
            VersionBounds::fromPivot('2.0', '3.0'),
            VersionBounds::fromPivot('4.0', '5.0'),
        ]);

        expect($this->svc->permitsAny($windows, PackageType::Composer, '2.5'))->toBeTrue()
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '4.1'))->toBeTrue()
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '3.5'))->toBeFalse();
    });

    it('permits everything when the windows are unlimited', function () {
        expect($this->svc->permitsAny(VersionWindows::unlimited(), PackageType::Composer, '999.0.0'))->toBeTrue();
    });

    // An empty window list is not "unlimited" — see VersionWindows::isUnlimited()'s
    // docblock. It means no unexpired assignment was found (e.g. one lapsed or was
    // revoked between two separate `now()` reads on the serving path), which must refuse
    // every version rather than fail open.
    it('refuses everything when there are no windows at all', function () {
        expect($this->svc->permitsAny(new VersionWindows([]), PackageType::Composer, '999.0.0'))->toBeFalse();
    });
});

describe('boundsFor', function () {
    it('reads the bounds off the pivot row', function () {
        $group = Group::factory()->create();
        $package = Package::factory()->inOrgOf($group)->create();
        $group->packages()->attach($package, ['version_min' => '1.0', 'version_max' => '2.0']);

        $bounds = $this->svc->boundsFor($group->fresh(), $package);

        expect($bounds)->not->toBeNull()
            ->and($bounds->min)->toBe('1.0')
            ->and($bounds->max)->toBe('2.0');
    });

    it('is unlimited when the assignment row has no bounds', function () {
        $group = Group::factory()->create();
        $package = Package::factory()->inOrgOf($group)->create();
        $group->packages()->attach($package);

        $bounds = $this->svc->boundsFor($group->fresh(), $package);

        expect($bounds)->not->toBeNull()
            ->and($bounds->isUnlimited())->toBeTrue();
    });

    // Fail CLOSED, not open: no pivot row means no assignment was found for this group,
    // which must be indistinguishable from "not available" to every caller — never treated
    // as an unbounded licence. See VersionEntitlement::boundsFor()'s own docblock and its
    // twin, VersionWindows::isUnlimited(), which states the identical rule for the org path.
    it('returns null when there is no assignment row at all', function () {
        $group = Group::factory()->create();
        $package = Package::factory()->inOrgOf($group)->create();

        $bounds = $this->svc->boundsFor($group, $package);

        expect($bounds)->toBeNull();
    });
});

describe('windowsForOrganization', function () {
    it('builds one window per unexpired assignment and does NOT hull them', function () {
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);
        $groupA = Group::factory()->for($org)->create();
        $groupB = Group::factory()->for($org)->create();

        $groupA->packages()->attach($package, ['version_min' => '2.0', 'version_max' => '3.0']);
        $groupB->packages()->attach($package, ['version_min' => '4.0', 'version_max' => '5.0']);

        $windows = $this->svc->windowsForOrganization($org, $package);

        expect($windows->isUnlimited())->toBeFalse()
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '2.5'))->toBeTrue()
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '4.1'))->toBeTrue()
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '3.5'))->toBeFalse();
    });

    it('is unlimited when any one assignment is unbounded', function () {
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);
        $groupA = Group::factory()->for($org)->create();
        $groupB = Group::factory()->for($org)->create();

        $groupA->packages()->attach($package, ['version_min' => '2.0', 'version_max' => '3.0']);
        $groupB->packages()->attach($package);

        $windows = $this->svc->windowsForOrganization($org, $package);

        expect($windows->isUnlimited())->toBeTrue();
    });

    // The race this closes: organizationPackage() found the package visible at its own
    // now(), but the one assignment that made it visible has since lapsed (or been
    // revoked) by the time this method runs its own now(). Zero unexpired assignments must
    // refuse every version, not fall back to "no assignment found, so admit everything".
    it('refuses everything for an expired assignment, contributing no window', function () {
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);
        $group = Group::factory()->for($org)->create();

        $group->packages()->attach($package, [
            'version_min' => '2.0',
            'version_max' => '3.0',
            'available_until' => now()->subDay(),
        ]);

        $windows = $this->svc->windowsForOrganization($org, $package);

        expect($windows->isUnlimited())->toBeFalse()
            ->and($windows->windows)->toBe([])
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '2.5'))->toBeFalse();
    });

    it('refuses everything when the package has no assignment in the organization at all', function () {
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);

        $windows = $this->svc->windowsForOrganization($org, $package);

        expect($windows->isUnlimited())->toBeFalse()
            ->and($this->svc->permitsAny($windows, PackageType::Composer, '999.0.0'))->toBeFalse();
    });

    it('runs as two queries total, not one per group of the organization', function () {
        // Three groups, so a naive one-query-per-group implementation would show up here as
        // 3 (or more) registry queries rather than 1 — the same shape OrgAccessServiceTest
        // pins packagesForOrganization() to, for the same reason: this sits on the
        // /o/{orgSlug} metadata path.
        //
        // The count is 2, not 1, and 2 IS the correct, permanent value here — do not
        // "restore" this to 1. The licence check at the top of the method
        // (organizationLicence()) always runs one query of its own before the registry
        // union query — here it finds no licence row and falls through — and that query is
        // a single indexed lookup on organization_package's own primary key, not a join,
        // so it costs nothing extra as the organization grows. The invariant this test
        // actually protects is that NEITHER query scales with the number of groups: 2
        // total regardless of whether there are 3 groups or 300, never 1-plus-N.
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);
        $groupA = Group::factory()->for($org)->create();
        $groupB = Group::factory()->for($org)->create();
        $groupC = Group::factory()->for($org)->create();

        $groupA->packages()->attach($package, ['version_min' => '2.0', 'version_max' => '3.0']);
        $groupB->packages()->attach($package, ['version_min' => '4.0', 'version_max' => '5.0']);
        $groupC->packages()->attach($package);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->svc->windowsForOrganization($org, $package);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($queryCount)->toBe(2);
    });
});
