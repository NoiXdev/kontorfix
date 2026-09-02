<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('shows a package with versions, dependencies and groups', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Kadenz']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'acme/widget']);
    PackageVersion::factory()->create([
        'package_id' => $pkg->id, 'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0',
        'metadata' => ['require' => ['php' => '^8.2', 'monolog/monolog' => '^3.0'], 'description' => 'x'],
    ]);
    $group->packages()->attach($pkg);

    $this->actingAs($this->admin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')
            ->where('package.name', 'acme/widget')
            ->has('versions', 1)
            ->where('versions.0.version', 'v1.0.0')
            ->where('versions.0.dependencies.runtime', ['php' => '^8.2', 'monolog/monolog' => '^3.0'])
            ->has('groups', 1)
            ->where('groups.0.name', 'Kadenz'));
});

it('is operator-gated', function () {
    $pkg = Package::factory()->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->get("/admin/packages/{$pkg->id}")->assertForbidden();
});
