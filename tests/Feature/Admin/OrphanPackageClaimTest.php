<?php

// Deleting a registry cascades its `group_package` rows, so a package attached nowhere
// else becomes an orphan. `assertCanAttachPackages()` narrowed its "is this foreign?" test
// to *attached* packages, which made every orphan claimable by any tenant that learned its
// id — along with the versions and dists already synced into it — and locked the original
// organization out, because their own re-attach then counted as foreign.

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

function orphanedPackage(Organization $owner): Package
{
    $group = Group::factory()->for($owner)->create();
    $package = Package::factory()->create();
    $package->groups()->attach($group->id);

    $group->delete();

    expect($package->fresh()->groups()->count())->toBe(0);

    return $package;
}

it('refuses a foreign tenant attaching an orphaned package to their own registry', function () {
    $victim = Organization::factory()->create();
    $orphan = orphanedPackage($victim);

    $attacker = Organization::factory()->create();
    $attackerGroup = Group::factory()->for($attacker)->create();
    $admin = User::factory()->for($attacker)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->from('/admin/groups')
        ->post("/admin/groups/{$attackerGroup->id}/packages", ['package_ids' => [$orphan->id]])
        ->assertForbidden();

    expect($orphan->fresh()->groups()->count())->toBe(0);
});

it('leaves the super-admin a way to re-home an orphan', function () {
    $victim = Organization::factory()->create();
    $orphan = orphanedPackage($victim);

    $newHome = Group::factory()->for($victim)->create();
    $super = User::factory()->for($victim)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);

    $this->actingAs($super)->from('/admin/groups')
        ->post("/admin/groups/{$newHome->id}/packages", ['package_ids' => [$orphan->id]])
        ->assertRedirect();

    expect($orphan->fresh()->groups()->pluck('id')->all())->toBe([$newHome->id]);
});

it('still lets an org admin attach a package already reachable in their own scope', function () {
    $org = Organization::factory()->create();
    $source = Group::factory()->for($org)->create();
    $target = Group::factory()->for($org)->create();

    $package = Package::factory()->create();
    $package->groups()->attach($source->id);

    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->from('/admin/groups')
        ->post("/admin/groups/{$target->id}/packages", ['package_ids' => [$package->id]])
        ->assertRedirect();

    expect($package->fresh()->groups()->count())->toBe(2);
});
