<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;
use App\Support\Licence\VersionBounds;
use App\Support\Licence\VersionWindows;
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

            expect($this->svc->permits($bounds, PackageType::Python, '1.0+cu118'))->toBeFalse();
        });

        it('logs an unparseable version only once', function () {
            Log::shouldReceive('warning')->once();

            $bounds = VersionBounds::fromPivot('1.0', '2.0');

            // A distinct version string from the other unparseable-version cases in this
            // file: the dedupe is keyed by version string and process-lifetime, so reusing
            // one already logged by a sibling test would make this assertion depend on
            // test order rather than on the behaviour under test.
            $this->svc->permits($bounds, PackageType::Python, '1.0+onlyonce');
            $this->svc->permits($bounds, PackageType::Python, '1.0+onlyonce');
        });

        it('serves an unparseable version normally when bounds are unlimited, doing no filtering and no logging', function () {
            Log::shouldReceive('warning')->never();

            expect($this->svc->permits(VersionBounds::unlimited(), PackageType::Python, '1.0+cu118'))->toBeTrue();
        });
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

    it('permits everything when there are no windows at all', function () {
        expect($this->svc->permitsAny(new VersionWindows([]), PackageType::Composer, '999.0.0'))->toBeTrue();
    });
});

describe('boundsFor', function () {
    it('reads the bounds off the pivot row', function () {
        $group = Group::factory()->create();
        $package = Package::factory()->inOrgOf($group)->create();
        $group->packages()->attach($package, ['version_min' => '1.0', 'version_max' => '2.0']);

        $bounds = $this->svc->boundsFor($group->fresh(), $package);

        expect($bounds->min)->toBe('1.0')
            ->and($bounds->max)->toBe('2.0');
    });

    it('is unlimited when the assignment row has no bounds', function () {
        $group = Group::factory()->create();
        $package = Package::factory()->inOrgOf($group)->create();
        $group->packages()->attach($package);

        $bounds = $this->svc->boundsFor($group->fresh(), $package);

        expect($bounds->isUnlimited())->toBeTrue();
    });

    it('is unlimited when there is no assignment row at all', function () {
        $group = Group::factory()->create();
        $package = Package::factory()->inOrgOf($group)->create();

        $bounds = $this->svc->boundsFor($group, $package);

        expect($bounds->isUnlimited())->toBeTrue();
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

    it('contributes no window for an expired assignment', function () {
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);
        $group = Group::factory()->for($org)->create();

        $group->packages()->attach($package, [
            'version_min' => '2.0',
            'version_max' => '3.0',
            'available_until' => now()->subDay(),
        ]);

        $windows = $this->svc->windowsForOrganization($org, $package);

        expect($windows->isUnlimited())->toBeTrue()
            ->and($windows->windows)->toBe([]);
    });

    it('is unlimited when the package has no assignment in the organization at all', function () {
        $org = Organization::factory()->create();
        $package = Package::factory()->create(['organization_id' => $org->id]);

        $windows = $this->svc->windowsForOrganization($org, $package);

        expect($windows->isUnlimited())->toBeTrue();
    });
});
