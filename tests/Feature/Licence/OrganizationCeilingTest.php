<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;

/** @return array{0: Group, 1: Package} */
function ceilingFixture(?string $licenceMin, ?string $licenceMax, ?string $until, string $rowMin, string $rowMax): array
{
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer]);

    $group->packages()->attach($package->id, ['version_min' => $rowMin, 'version_max' => $rowMax]);

    if ($licenceMin !== null || $licenceMax !== null || $until !== null) {
        $org->licensedPackages()->attach($package->id, [
            'version_min' => $licenceMin, 'version_max' => $licenceMax, 'available_until' => $until,
        ]);
    }

    return [$group, $package];
}

it('leaves the registry window alone when no licence exists', function () {
    [$group, $package] = ceilingFixture(null, null, null, '1.0.0', '5.0.0');

    $bounds = app(VersionEntitlement::class)->boundsFor($group, $package);

    expect($bounds->min)->toBe('1.0.0')->and($bounds->max)->toBe('5.0.0');
});

it('narrows the registry window to the licence', function () {
    [$group, $package] = ceilingFixture('1.0.0', '2.9.9', null, '1.0.0', '5.0.0');

    $bounds = app(VersionEntitlement::class)->boundsFor($group, $package);

    expect($bounds->min)->toBe('1.0.0')->and($bounds->max)->toBe('2.9.9');
});

it('returns null when the registry window falls outside the licence', function () {
    [$group, $package] = ceilingFixture('1.0.0', '2.9.9', null, '3.0.0', '5.0.0');

    expect(app(VersionEntitlement::class)->boundsFor($group, $package))->toBeNull();
});

it('returns null once the licence has expired, even with a live registry row', function () {
    [$group, $package] = ceilingFixture('1.0.0', '9.0.0', now()->subDay()->toDateTimeString(), '1.0.0', '5.0.0');

    expect(app(VersionEntitlement::class)->boundsFor($group, $package))->toBeNull();
});

it('still returns null when there is no registry assignment at all', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer]);
    $org->licensedPackages()->attach($package->id, ['version_min' => '1.0.0']);

    // An org licence alone does not make a package servable inside a registry.
    expect(app(VersionEntitlement::class)->boundsFor($group, $package))->toBeNull();
});
