<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

it('assigns pool packages to groups with constraints', function () {
    $group = Group::factory()->create(['slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'kadenz/shop-bridge']);
    PackageVersion::factory()->for($pkg)->create(['version' => '1.2.0.0']);

    $group->packages()->attach($pkg, ['available_until' => now()->addYear()]);

    expect($group->packages()->first()->is($pkg))->toBeTrue()
        ->and($pkg->type)->toBe(PackageType::Composer)
        ->and($group->packages()->first()->pivot->available_until)->not->toBeNull();
});

it('casts the pivot available_until to a datetime', function () {
    $group = Group::factory()->create();
    $pkg = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($pkg, ['available_until' => now()->addYear()]);

    expect($group->packages()->first()->pivot->available_until)
        ->toBeInstanceOf(Carbon::class);
});

it('enforces the unique constraint on package type and name', function () {
    Package::factory()->create(['name' => 'acme/demo']);

    expect(fn () => Package::factory()->create(['name' => 'acme/demo']))
        ->toThrow(QueryException::class);
});

it('prevents duplicate package assignments to the same group', function () {
    $group = Group::factory()->create();
    $pkg = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($pkg);

    expect(fn () => $group->packages()->attach($pkg))
        ->toThrow(QueryException::class);
});

it('links groups to an organization owner', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    expect($group->organization->is($org))->toBeTrue();
});
