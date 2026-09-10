<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

// A super-admin (or grandfathered operator-org admin) administers every organization at
// once, so `assertAdministersOrg()`/`assertAdministersGroup()` alone would let one who has
// deliberately scoped the console down to `$ownOrg` still open, edit or delete a completely
// unrelated organization's registry by URL. `assertAdministersGroupInScope()` must ask the
// ACTIVE SCOPE instead.
function groupScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to view a foreign-org registry while scoped to a different organization', function () {
    $admin = groupScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = Group::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->get(route('admin.groups.show', $foreign))
        ->assertForbidden();
});

it('refuses to update a foreign-org registry while scoped to a different organization', function () {
    $admin = groupScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = Group::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->put(route('admin.groups.update', $foreign), ['name' => 'renamed'])
        ->assertForbidden();

    expect($foreign->fresh()->name)->toBe('theirs');
});

it('refuses to attach a package to a foreign-org registry while scoped to a different organization', function () {
    $admin = groupScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = Group::factory()->create();
    $package = Package::factory()->inOrgOf($foreign)->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->post(route('admin.groups.packages.store', $foreign), ['package_ids' => [$package->id]])
        ->assertForbidden();

    expect($foreign->packages()->count())->toBe(0);
});

it('refuses to update a package assignment on a foreign-org registry while scoped to a different organization', function () {
    $admin = groupScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = Group::factory()->create();
    $package = Package::factory()->inOrgOf($foreign)->create();
    $foreign->packages()->attach($package->id);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->put(route('admin.groups.packages.update', [$foreign, $package]), ['available_until' => null])
        ->assertForbidden();
});

it('refuses to detach a package from a foreign-org registry while scoped to a different organization', function () {
    $admin = groupScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = Group::factory()->create();
    $package = Package::factory()->inOrgOf($foreign)->create();
    $foreign->packages()->attach($package->id);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete(route('admin.groups.packages.destroy', [$foreign, $package]))
        ->assertForbidden();

    expect($foreign->packages()->count())->toBe(1);
});

it('refuses to delete a foreign-org registry while scoped to a different organization', function () {
    $admin = groupScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = Group::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete(route('admin.groups.destroy', $foreign))
        ->assertForbidden();

    expect(Group::find($foreign->id))->not->toBeNull();
});
