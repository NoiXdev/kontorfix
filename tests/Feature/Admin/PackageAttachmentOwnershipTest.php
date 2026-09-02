<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/*
 * `assertPackagesReachableIn()` now compares `packages.organization_id` against the
 * caller's organizations directly, rather than reconstructing an owner from the
 * registries a package happens to be attached to. These two cases are the ones that
 * distinguish the comparison from the old reconstruction:
 *
 *   - a package owned by another organization must stay refused, attached or not
 *     (attachment is no longer what makes it foreign);
 *   - a package owned by the caller's own organization must now be attachable even
 *     before it has been attached to any registry at all — the old whereDoesntHave()
 *     check refused this case too, since it could not tell "unattached and foreign"
 *     from "unattached and mine".
 */

it('refuses attaching a package to a registry of another organization', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $mine = Group::factory()->for($org)->create();

    $theirs = Group::factory()->create();
    $foreign = Package::factory()->inOrgOf($theirs)->create();

    $this->actingAs($admin)
        ->post(route('admin.groups.packages.store', $mine), ['package_ids' => [$foreign->id]])
        ->assertForbidden();

    expect($mine->packages()->count())->toBe(0);
});

it('allows attaching a package owned by the caller that is not yet attached anywhere', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $mine = Group::factory()->for($org)->create();

    // Owned by the same organization as $mine, but not attached to any registry — the
    // case the old attachment-reconstruction check could not distinguish from a
    // foreign-owned orphan, and so refused along with it.
    $unattached = Package::factory()->inOrgOf($mine)->create();

    $this->actingAs($admin)
        ->post(route('admin.groups.packages.store', $mine), ['package_ids' => [$unattached->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($mine->packages()->count())->toBe(1);
});
