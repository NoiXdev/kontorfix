<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;

it('assigns pool packages to groups with constraints', function () {
    $pkg = Package::factory()->create(['name' => 'kadenz/shop-bridge']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.2.0.0']);
    $group = Group::factory()->create(['slug' => 'kadenz']);

    $group->packages()->attach($pkg, ['available_until' => now()->addYear()]);

    expect($group->packages()->first()->is($pkg))->toBeTrue()
        ->and($pkg->type)->toBe(PackageType::Composer)
        ->and($group->packages()->first()->pivot->available_until)->not->toBeNull();
});

it('links groups to an organization owner', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    expect($group->organization->is($org))->toBeTrue();
});
