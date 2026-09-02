<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;

it('serves packages.json with metadata-url and available packages', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});

it('serves p2 metadata for an assigned package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $this->withHeaders(tokenHeaderFor($group))
        ->getJson('/r/kadenz/p2/acme/demo.json')
        ->assertOk()->assertJsonStructure(['packages' => ['acme/demo']]);
});

it('returns 401 without token and 404 for unassigned packages', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $other = Package::factory()->create(['name' => 'acme/secret']);
    PackageVersion::factory()->for($other)->create();

    $this->getJson('/r/kadenz/packages.json')->assertUnauthorized();
    $this->withHeaders(tokenHeaderFor($group))
        ->getJson('/r/kadenz/p2/acme/secret.json')->assertNotFound(); // never 403: no leak of whether the package exists
});

it('allows anonymous access to public groups', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'pub', 'public' => true]);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/open']);
    $group->packages()->attach($pkg);

    $this->getJson('/r/pub/packages.json')
        ->assertOk()->assertJsonPath('available-packages.0', 'acme/open');
});

it('returns 404 (not 401) when a valid token has no access to the group', function () {
    $victim = Group::factory()->for(Organization::factory())->create(['slug' => 'victim']);
    $pkg = Package::factory()->inOrgOf($victim)->create(['name' => 'acme/demo']);
    $victim->packages()->attach($pkg);

    // Token from a different group/org: authenticated, but without access → 404, not 401.
    $attacker = Group::factory()->for(Organization::factory())->create(['slug' => 'attacker']);

    $this->withHeaders(tokenHeaderFor($attacker))
        ->getJson('/r/victim/packages.json')->assertNotFound();
});
