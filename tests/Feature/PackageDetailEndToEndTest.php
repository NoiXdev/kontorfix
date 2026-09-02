<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

it('shows the same package in admin detail and read-only portal detail', function () {
    config(['app.url' => 'https://reg.example.test']);

    $operatorAdmin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $org = Organization::factory()->create();
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create(['slug' => 'acme']);
    $pkg = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'acme/widget']);
    PackageVersion::factory()->create(['package_id' => $pkg->id, 'version' => '1.0.0.0', 'version_pretty' => 'v1.0.0', 'metadata' => ['require' => ['php' => '^8.2']]]);
    $group->packages()->attach($pkg);

    // Admin detail: versions + dependencies + registry
    $this->actingAs($operatorAdmin)->get("/admin/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Show')
            ->where('package.name', 'acme/widget')
            ->has('versions', 1)
            ->where('versions.0.dependencies.runtime', ['php' => '^8.2'])
            ->has('groups', 1));

    // Portal detail (read-only) for the customer, including install snippet
    $this->actingAs($member)->get("/portal/registries/{$group->id}/packages/{$pkg->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')
            ->where('package.name', 'acme/widget')->has('versions', 1)
            ->where('install', fn ($v) => str_contains($v, 'acme/widget')));

    // Foreign registry remains locked down — $pkg is deliberately also attached to a group
    // outside its own org here, to prove the foreign registry's view stays forbidden; the
    // org-pairing sweep guard in tests/Pest.php is expected to trip on this attach, and
    // stays doing so.
    $foreign = Group::factory()->for(Organization::factory()->create())->create();
    $foreign->packages()->attach($pkg);
    $this->actingAs($member)->get("/portal/registries/{$foreign->id}/packages/{$pkg->id}")->assertForbidden();
});
