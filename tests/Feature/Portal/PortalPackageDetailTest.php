<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->org = Organization::factory()->create();
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'acme']);
    $this->pkg = Package::factory()->create(['type' => 'composer', 'name' => 'acme/widget']);
    PackageVersion::factory()->create(['package_id' => $this->pkg->id, 'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0', 'metadata' => ['require' => ['php' => '^8.2']]]);
    $this->group->packages()->attach($this->pkg);
});

it('shows a package detail within the customers own registry', function () {
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}/packages/{$this->pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('package.name', 'acme/widget')->has('versions', 1)
            ->where('install', fn ($v) => str_contains($v, 'acme/widget')));
});

it('sends the portal detail page its versions newest first', function () {
    foreach (['1.9.0', '1.10.0', '1.2.0'] as $v) {
        PackageVersion::factory()->create(['package_id' => $this->pkg->id, 'version' => $v, 'version_pretty' => $v]);
    }

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}/packages/{$this->pkg->id}")
        ->assertInertia(fn ($p) => $p->where(
            'versions.0.version', '1.10.0'
        )->where('versions.1.version', '1.9.0'));
});

it('forbids a package not in the members registry', function () {
    $otherGroup = Group::factory()->for(Organization::factory()->create())->create();
    $otherPkg = Package::factory()->create();
    $otherGroup->packages()->attach($otherPkg);

    // foreign registry → 403 (GroupPolicy)
    $this->actingAs($this->member)->get("/portal/registries/{$otherGroup->id}/packages/{$otherPkg->id}")->assertForbidden();

    // own registry, but package not assigned → 404
    $unassigned = Package::factory()->create();
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}/packages/{$unassigned->id}")->assertNotFound();
});
