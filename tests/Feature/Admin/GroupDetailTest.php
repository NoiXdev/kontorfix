<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Upstream;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('shows a registry with packages, domains, upstreams, tokens and a setup snippet', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Kadenz', 'slug' => 'kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/widget']);
    $group->packages()->attach($pkg);
    Domain::factory()->for($group)->create(['hostname' => 'packages.kadenz.test']);
    Upstream::factory()->for($group)->create();

    $this->actingAs($this->admin)->get("/admin/groups/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/groups/Show')
            ->where('group.name', 'Kadenz')
            ->has('packages', 1)->where('packages.0.name', 'acme/widget')
            ->has('domains', 1)->where('domains.0.hostname', 'packages.kadenz.test')
            ->has('upstreams', 1)
            ->has('setup.composer'));
});

it('is operator-gated', function () {
    $group = Group::factory()->for(Organization::factory())->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->get("/admin/groups/{$group->id}")->assertForbidden();
});
