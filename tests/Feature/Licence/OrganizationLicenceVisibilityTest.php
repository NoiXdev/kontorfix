<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\RegistryAccessService;
use Illuminate\Support\Facades\DB;

it('offers an org-licensed package at the org endpoint with no registry assignment', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz']);
    $org->licensedPackages()->attach($package->id, ['version_min' => '1.0.0']);

    $names = app(RegistryAccessService::class)->packagesForOrganization($org)->pluck('name');

    expect($names)->toContain('acme/lizenz');
});

it('withdraws an expired licence from the org endpoint even with a live registry row', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/alt']);
    $group->packages()->attach($package->id);
    $org->licensedPackages()->attach($package->id, ['available_until' => now()->subDay()]);

    $names = app(RegistryAccessService::class)->packagesForOrganization($org)->pluck('name');

    expect($names)->not->toContain('acme/alt');
});

it('withdraws an expired licence from the registry index too', function () {
    // Otherwise the registry keeps advertising a package in available-packages that every
    // download refuses — the advertised set and the served set must stay equal.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/alt']);
    $group->packages()->attach($package->id);
    $org->licensedPackages()->attach($package->id, ['available_until' => now()->subDay()]);

    $names = app(RegistryAccessService::class)->packagesFor($group)->pluck('name');

    expect($names)->not->toContain('acme/alt');
});

it('leaves a registry index untouched while the licence is live', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/aktiv']);
    $group->packages()->attach($package->id);
    $org->licensedPackages()->attach($package->id, ['available_until' => now()->addYear()]);

    expect(app(RegistryAccessService::class)->packagesFor($group)->pluck('name'))->toContain('acme/aktiv');
});

it('does not let one organization licence reach another organization', function () {
    $mine = Organization::factory()->create();
    $theirs = Organization::factory()->create();
    $group = Group::factory()->for($theirs)->create();
    $package = Package::factory()->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/fremd']);
    $mine->licensedPackages()->attach($package->id);

    expect(app(RegistryAccessService::class)->packagesForOrganization($theirs)->pluck('name'))
        ->not->toContain('acme/fremd')
        ->and(app(RegistryAccessService::class)->packagesFor($group)->pluck('name'))
        ->not->toContain('acme/fremd');
});

it('answers the org endpoint in a single query', function () {
    $org = Organization::factory()->create();
    Group::factory()->count(3)->for($org)->create();

    DB::enableQueryLog();
    app(RegistryAccessService::class)->packagesForOrganization($org);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1);
});
