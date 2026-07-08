<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\RegistryToken;

function tokenHeaderFor(Group $group): array
{
    [, $plain] = RegistryToken::issue($group->organization, 'test', $group);

    return ['Authorization' => 'Basic '.base64_encode('token:'.$plain)];
}

it('serves packages.json with metadata-url and available packages', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
    $group->packages()->attach($pkg);

    $res = $this->withHeaders(tokenHeaderFor($group))->getJson('/r/kadenz/packages.json');

    $res->assertOk()
        ->assertJsonPath('metadata-url', '/r/kadenz/p2/%package%.json')
        ->assertJsonPath('available-packages.0', 'acme/demo');
});

it('serves p2 metadata for an assigned package', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->create(['name' => 'acme/demo']);
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
        ->getJson('/r/kadenz/p2/acme/secret.json')->assertNotFound(); // nie 403: kein Leak, ob es das Paket gibt
});

it('returns 404 for a public group is not required but anonymous access to public groups works', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'pub', 'public' => true]);
    $pkg = Package::factory()->create(['name' => 'acme/open']);
    $group->packages()->attach($pkg);

    $this->getJson('/r/pub/packages.json')
        ->assertOk()->assertJsonPath('available-packages.0', 'acme/open');
});
