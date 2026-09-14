<?php

use App\Enums\PackageType;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;
use App\Support\Licence\VersionBounds;

function licenceEntitlement(): VersionEntitlement
{
    return app(VersionEntitlement::class);
}

it('returns null when the organization holds no licence', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);

    expect(licenceEntitlement()->organizationLicence($org->id, $package))->toBeNull();
});

it('reads bounds and period off the licence row', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);
    $org->licensedPackages()->attach($package->id, [
        'version_min' => '1.0.0', 'version_max' => '2.9.9', 'available_until' => now()->addYear(),
    ]);

    $licence = licenceEntitlement()->organizationLicence($org->id, $package);

    expect($licence)->not->toBeNull()
        ->and($licence->bounds->min)->toBe('1.0.0')
        ->and($licence->bounds->max)->toBe('2.9.9')
        ->and($licence->isExpired())->toBeFalse();
});

it('reports a lapsed licence as expired rather than as absent', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);
    $org->licensedPackages()->attach($package->id, ['available_until' => now()->subDay()]);

    $licence = licenceEntitlement()->organizationLicence($org->id, $package);

    expect($licence)->not->toBeNull()
        ->and($licence->isExpired())->toBeTrue();
});

it('treats a null period as never expiring', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true]);
    $org->licensedPackages()->attach($package->id, ['available_until' => null]);

    expect(licenceEntitlement()->organizationLicence($org->id, $package)->isExpired())->toBeFalse();
});

it('intersects two overlapping windows', function () {
    $result = licenceEntitlement()->intersect(
        new VersionBounds('1.0.0', '2.9.9'),
        new VersionBounds('2.0.0', '5.0.0'),
        PackageType::Composer,
    );

    expect($result->min)->toBe('2.0.0')->and($result->max)->toBe('2.9.9');
});

it('returns null for disjoint windows', function () {
    expect(licenceEntitlement()->intersect(
        new VersionBounds('1.0.0', '2.9.9'),
        new VersionBounds('3.0.0', '5.0.0'),
        PackageType::Composer,
    ))->toBeNull();
});

it('keeps the other side when one is unlimited', function () {
    $result = licenceEntitlement()->intersect(
        VersionBounds::unlimited(),
        new VersionBounds('3.0.0', '5.0.0'),
        PackageType::Composer,
    );

    expect($result->min)->toBe('3.0.0')->and($result->max)->toBe('5.0.0');
});

it('stays unlimited when both are', function () {
    expect(licenceEntitlement()->intersect(
        VersionBounds::unlimited(),
        VersionBounds::unlimited(),
        PackageType::Composer,
    )->isUnlimited())->toBeTrue();
});

it('orders python bounds by PEP 440 rather than by semver', function () {
    // 1.0.post1 is ABOVE 1.0 in PEP 440; a semver comparator cannot say so.
    $result = licenceEntitlement()->intersect(
        new VersionBounds('1.0', '2.0'),
        new VersionBounds('1.0.post1', '3.0'),
        PackageType::Python,
    );

    expect($result->min)->toBe('1.0.post1')->and($result->max)->toBe('2.0');
});

it('fails closed on an unparseable bound', function () {
    expect(licenceEntitlement()->intersect(
        new VersionBounds('nicht-eine-version', null),
        new VersionBounds('1.0.0', null),
        PackageType::Composer,
    ))->toBeNull();
});

it('fails closed on a single-sided unparseable min bound (composer)', function () {
    expect(licenceEntitlement()->intersect(
        VersionBounds::unlimited(),
        new VersionBounds('not-a-version', null),
        PackageType::Composer,
    ))->toBeNull();
});

it('fails closed on a single-sided unparseable max bound (composer)', function () {
    expect(licenceEntitlement()->intersect(
        VersionBounds::unlimited(),
        new VersionBounds(null, 'not-a-version'),
        PackageType::Composer,
    ))->toBeNull();
});

it('fails closed on a single-sided unparseable min bound (python)', function () {
    expect(licenceEntitlement()->intersect(
        VersionBounds::unlimited(),
        new VersionBounds('not-a-version', null),
        PackageType::Python,
    ))->toBeNull();
});

it('fails closed on a single-sided unparseable max bound (python)', function () {
    expect(licenceEntitlement()->intersect(
        VersionBounds::unlimited(),
        new VersionBounds(null, 'not-a-version'),
        PackageType::Python,
    ))->toBeNull();
});

it('refuses to intersect bounds for a Docker package rather than comparing image tags as versions', function () {
    licenceEntitlement()->intersect(
        new VersionBounds('1.2.3', null),
        new VersionBounds('4.5.6', null),
        PackageType::Docker,
    );
})->throws(LogicException::class);
