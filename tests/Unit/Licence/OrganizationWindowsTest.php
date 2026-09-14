<?php

// tests/Unit/Licence/OrganizationWindowsTest.php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Licence\VersionEntitlement;

it('keeps one window per registry assignment when no licence exists', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer]);

    Group::factory()->for($org)->create()->packages()->attach($package->id, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    Group::factory()->for($org)->create()->packages()->attach($package->id, ['version_min' => '4.0.0', 'version_max' => '5.0.0']);

    $entitlement = app(VersionEntitlement::class);
    $windows = $entitlement->windowsForOrganization($org, $package);

    expect($entitlement->permitsAny($windows, PackageType::Composer, '2.5.0'))->toBeTrue()
        ->and($entitlement->permitsAny($windows, PackageType::Composer, '4.5.0'))->toBeTrue()
        // Two windows, not a hull: 3.5.0 sits between them and must stay refused.
        ->and($entitlement->permitsAny($windows, PackageType::Composer, '3.5.0'))->toBeFalse();
});

it('serves exactly the licence window when one exists', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer]);
    $org->licensedPackages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '2.9.9']);

    // A registry row reaching higher must not widen the org endpoint.
    Group::factory()->for($org)->create()->packages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '9.0.0']);

    $entitlement = app(VersionEntitlement::class);
    $windows = $entitlement->windowsForOrganization($org, $package);

    expect($entitlement->permitsAny($windows, PackageType::Composer, '2.0.0'))->toBeTrue()
        ->and($entitlement->permitsAny($windows, PackageType::Composer, '5.0.0'))->toBeFalse();
});

it('serves the licence window with no registry assignment at all', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer]);
    $org->licensedPackages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '2.9.9']);

    $entitlement = app(VersionEntitlement::class);

    expect($entitlement->permitsAny($entitlement->windowsForOrganization($org, $package), PackageType::Composer, '2.0.0'))
        ->toBeTrue();
});

it('denies everything once the licence has expired', function () {
    $org = Organization::factory()->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer]);
    $org->licensedPackages()->attach($package->id, ['available_until' => now()->subDay()]);
    Group::factory()->for($org)->create()->packages()->attach($package->id);

    $entitlement = app(VersionEntitlement::class);

    expect($entitlement->permitsAny($entitlement->windowsForOrganization($org, $package), PackageType::Composer, '1.0.0'))
        ->toBeFalse();
});
