<?php

// A package licensed org-wide but assigned to no registry was invisible in the portal:
// PortalPackages walks the organization's groups, so a package reachable only through
// `/o/{orgSlug}` had no row. The customer collects their organization-wide token from that
// very portal and could not see there what the token would let them pull.
//
// It appears as an ordinary row whose registry column names the organization-wide source
// instead of a registry — the package list is the page you open to learn what you have, so
// an incomplete one is the defect.

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\Portal\PortalPackages;

beforeEach(function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create(['portal_enabled' => true]);
    $this->package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);
    PackageVersion::factory()->for($this->package)->create(['version' => '2.0.0', 'metadata' => []]);
});

it('lists a package that only an org licence makes reachable', function () {
    $this->customer->licensedPackages()->attach($this->package->id, ['version_min' => '1.0.0', 'version_max' => '2.9.9']);

    $rows = app(PortalPackages::class)->for($this->customer);

    expect($rows->pluck('package.name'))->toContain('acme/lizenz');
});

it('marks that row as reachable through the organization-wide source, not through a registry', function () {
    $this->customer->licensedPackages()->attach($this->package->id, ['version_min' => '1.0.0']);

    $row = app(PortalPackages::class)->for($this->customer)->firstWhere('package.name', 'acme/lizenz');

    expect($row['org_wide'])->toBeTrue()
        ->and($row['groups'])->toBeEmpty()
        ->and($row['in_force'])->toBeTrue();
});

it('does not list it once the licence has lapsed', function () {
    $this->customer->licensedPackages()->attach($this->package->id, ['available_until' => now()->subDay()]);

    $rows = app(PortalPackages::class)->for($this->customer);

    expect($rows->pluck('package.name'))->not->toContain('acme/lizenz');
});

it('leaves a package that a registry already carries as a registry row', function () {
    // The licence is the ceiling on that registry, not a second way of listing the same
    // package — a row claiming both would tell the customer they have it twice.
    $group = Group::factory()->for($this->customer)->create(['portal_enabled' => true, 'name' => 'Produktion']);
    $group->packages()->attach($this->package->id);
    $this->customer->licensedPackages()->attach($this->package->id, ['version_min' => '1.0.0']);

    $row = app(PortalPackages::class)->for($this->customer)->firstWhere('package.name', 'acme/lizenz');

    expect($row['org_wide'])->toBeFalse()
        ->and($row['groups'])->toHaveCount(1);
});

it('leaves an ordinary registry package untouched', function () {
    $group = Group::factory()->for($this->customer)->create(['portal_enabled' => true]);
    $own = Package::factory()->for($this->customer)->create(['type' => PackageType::Composer, 'name' => 'kunde/eigen']);
    $group->packages()->attach($own->id);

    $row = app(PortalPackages::class)->for($this->customer)->firstWhere('package.name', 'kunde/eigen');

    expect($row['org_wide'])->toBeFalse()
        ->and($row['groups'])->toHaveCount(1);
});
