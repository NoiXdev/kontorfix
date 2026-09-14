<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;

it('serves an org-licensed package at /o/ and nowhere else', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $group = Group::factory()->for($customer)->create();
    $package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);
    PackageVersion::factory()->for($package)->create(['version' => '2.0.0', 'metadata' => []]);
    $customer->licensedPackages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '2.9.9']);

    $this->get(orgRegistryPath($customer).'/packages.json', orgTokenHeaderFor($customer))
        ->assertOk()
        ->assertJsonPath('available-packages', ['acme/lizenz']);

    // No group_package row, so the registry itself must not serve it.
    $this->get(registryPath($group).'/p2/acme/lizenz.json', tokenHeaderFor($group))
        ->assertNotFound();
});

it('refuses a version above the licence through the registry', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $group = Group::factory()->for($customer)->create();
    $package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);
    PackageVersion::factory()->for($package)->create(['version' => '2.0.0', 'metadata' => []]);
    PackageVersion::factory()->for($package)->create(['version' => '3.0.0', 'metadata' => []]);

    $group->packages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '5.0.0']);
    $customer->licensedPackages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '2.9.9']);

    $body = $this->get(registryPath($group).'/p2/acme/lizenz.json', tokenHeaderFor($group))
        ->assertOk()->getContent();

    expect($body)->toContain('2.0.0')->not->toContain('3.0.0');
});

it('stops serving entirely once the licence lapses', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $group = Group::factory()->for($customer)->create();
    $package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/alt',
    ]);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0', 'metadata' => []]);
    $group->packages()->attach($package->id);
    $customer->licensedPackages()->attach($package->id, ['available_until' => now()->subDay()]);

    $this->get(registryPath($group).'/p2/acme/alt.json', tokenHeaderFor($group))->assertNotFound();
    $this->get(orgRegistryPath($customer).'/p2/acme/alt.json', orgTokenHeaderFor($customer))->assertNotFound();
});
