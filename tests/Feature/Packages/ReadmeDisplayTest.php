<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('sends the readme html to the admin detail page', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $package = Package::factory()->create(['organization_id' => $org->id, 'readme_html' => '<h1>Projekt</h1>']);
    $package->groups()->attach(homeRegistryId($admin));

    $this->actingAs($admin)
        ->get(route('admin.packages.show', $package))
        ->assertInertia(fn ($page) => $page->where('package.readme_html', '<h1>Projekt</h1>'));
});

it('sends null when the package has no readme', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $package = Package::factory()->create(['organization_id' => $org->id, 'readme_html' => null]);
    $package->groups()->attach(homeRegistryId($admin));

    $this->actingAs($admin)
        ->get(route('admin.packages.show', $package))
        ->assertInertia(fn ($page) => $page->where('package.readme_html', null));
});

it('sends the readme html to the portal detail page', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'readme_html' => '<h1>Projekt</h1>']);
    $group->packages()->attach($package);

    $this->actingAs($member)
        ->get("/portal/registries/{$group->id}/packages/{$package->id}")
        ->assertInertia(fn ($page) => $page->where('package.readme_html', '<h1>Projekt</h1>'));
});

it('sends null on the portal detail page when the package has no readme', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'readme_html' => null]);
    $group->packages()->attach($package);

    $this->actingAs($member)
        ->get("/portal/registries/{$group->id}/packages/{$package->id}")
        ->assertInertia(fn ($page) => $page->where('package.readme_html', null));
});
