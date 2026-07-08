<?php

use App\Enums\PackageType;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;

it('serves composer packages.json at the domain root with a root-relative metadata-url', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});

it('serves p2 metadata at the domain root with a domain-root dist url', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/p2/acme/demo.json');

    $res->assertOk()->assertJsonStructure(['packages' => ['acme/demo']]);
    $dist = data_get($res->json(), 'packages.acme/demo.0.dist.url');
    expect($dist)->toStartWith('http://packages.kadenz.test/dists/');
});

it('serves npm packument at the domain root', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    $pkg = Package::factory()->create(['name' => 'acme-demo', 'type' => PackageType::Npm]);
    PackageVersion::factory()->for($pkg)->create();
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(array_merge(['Host' => 'packages.kadenz.test'], tokenHeaderFor($group)))
        ->getJson('http://packages.kadenz.test/acme-demo');

    $res->assertOk()->assertJsonPath('name', 'acme-demo');
});

it('returns 404 for an unknown host', function () {
    $this->getJson('http://not-a-registry.test/packages.json')->assertNotFound();
});

it('still serves the slug route unchanged after the domain-resolution refactor', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});
