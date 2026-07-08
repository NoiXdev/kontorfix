<?php

// tests/Feature/Registry/NpmMetadataTest.php
use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;

it('serves an npm packument for an assigned scoped package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => '@noixdev/ui-kit', 'dist_tags' => ['latest' => '1.0.0']]);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => ['name' => '@noixdev/ui-kit', 'version' => '1.0.0'], 'dist_tarball_name' => 'ui-kit-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    $response = $this->withHeaders(tokenHeaderFor($group))
        ->getJson('/r/kadenz/@noixdev/ui-kit')
        ->assertOk()
        ->assertJsonPath('name', '@noixdev/ui-kit')
        ->assertJsonPath('dist-tags.latest', '1.0.0');

    // "1.0.0" als Array-Key enthält selbst Punkte, daher lässt sich der Pfad nicht per
    // Dot-Notation (assertJsonPath) adressieren — Arr::get() splittet naiv auf jeden ".".
    expect($response->json('versions')['1.0.0']['dist']['tarball'])
        ->toBe('http://localhost/r/kadenz/@noixdev/ui-kit/-/ui-kit-1.0.0.tgz');
});

it('serves an unscoped packument', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'leftpad']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => ['name' => 'leftpad'], 'dist_tarball_name' => 'leftpad-1.0.0.tgz']);
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/leftpad')->assertOk()->assertJsonPath('name', 'leftpad');
});

it('401 without token, 404 for unassigned npm package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $secret = Package::factory()->create(['type' => PackageType::Npm, 'name' => 'secret']);
    PackageVersion::factory()->for($secret)->create(['version' => '1.0.0', 'version_pretty' => '1.0.0', 'metadata' => [], 'dist_tarball_name' => 'x.tgz']);

    $this->getJson('/r/kadenz/leftpad')->assertUnauthorized();
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/secret')->assertNotFound();
});

it('does not shadow the composer root or p2 routes', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json')->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json');
});
