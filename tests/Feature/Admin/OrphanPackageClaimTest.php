<?php

// Deleting a registry cascades its `group_package` rows, so a package attached nowhere
// else becomes an orphan. `assertCanAttachPackages()` narrowed its "is this foreign?" test
// to *attached* packages, which made every orphan claimable by any tenant that learned its
// id — along with the versions and dists already synced into it — and locked the original
// organization out, because their own re-attach then counted as foreign.

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
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

// The same guard lives twice: ScopesToAdministeredOrgs (web) and ScopesApiToUser (API).
// Only the web copy lost the orphan exemption, so `/api/v1` stayed claimable. These cases
// pin the API half; the "own package" cases below each of them are reachability anchors —
// they prove the 403 comes from the attach guard and not from auth, the key permission,
// route-model binding or `Rule::exists`, all of which are identical in both requests.
it('refuses a foreign tenant claiming an orphan through the api package sync', function () {
    $victim = Organization::factory()->create();
    $orphan = orphanedPackage($victim);

    $attacker = Organization::factory()->create();
    $attackerGroup = Group::factory()->for($attacker)->create();
    $admin = User::factory()->for($attacker)->create(['role' => UserRole::Admin]);
    [, $key] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($key)
        ->putJson("/api/v1/groups/{$attackerGroup->id}/packages", ['package_ids' => [$orphan->id]])
        ->assertForbidden();

    expect($orphan->fresh()->groups()->count())->toBe(0);
});

it('still syncs a package of the own organization through the api', function () {
    // Reachability anchor for the case above: same actor, same key, same route, same
    // validation — only the package's ownership differs, and this one goes through.
    $attacker = Organization::factory()->create();
    $attackerGroup = Group::factory()->for($attacker)->create();
    $own = Package::factory()->create();
    $own->groups()->attach($attackerGroup->id);

    $admin = User::factory()->for($attacker)->create(['role' => UserRole::Admin]);
    [, $key] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($key)
        ->putJson("/api/v1/groups/{$attackerGroup->id}/packages", ['package_ids' => [$own->id]])
        ->assertSuccessful();

    expect($attackerGroup->packages()->count())->toBe(1);
});

it('refuses a foreign tenant claiming an orphan while creating a registry through the api', function () {
    $victim = Organization::factory()->create();
    $orphan = orphanedPackage($victim);

    $attacker = Organization::factory()->create();
    $admin = User::factory()->for($attacker)->create(['role' => UserRole::Admin]);
    [, $key] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($key)->postJson('/api/v1/groups', [
        'name' => 'Claim',
        'slug' => 'claim-orphan-api',
        'package_ids' => [$orphan->id],
    ])->assertForbidden();

    expect(Group::where('slug', 'claim-orphan-api')->exists())->toBeFalse()
        ->and($orphan->fresh()->groups()->count())->toBe(0);
});

it('still creates a registry seeded with a package of the own organization through the api', function () {
    // Reachability anchor for the case above.
    $attacker = Organization::factory()->create();
    $existing = Group::factory()->for($attacker)->create();
    $own = Package::factory()->create();
    $own->groups()->attach($existing->id);

    $admin = User::factory()->for($attacker)->create(['role' => UserRole::Admin]);
    [, $key] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $this->withToken($key)->postJson('/api/v1/groups', [
        'name' => 'Legit',
        'slug' => 'legit-seed-api',
        'package_ids' => [$own->id],
    ])->assertCreated();

    expect($own->fresh()->groups()->count())->toBe(2);
});

it('leaves the super-admin a way to re-home an orphan through the api', function () {
    $victim = Organization::factory()->create();
    $orphan = orphanedPackage($victim);

    $newHome = Group::factory()->for($victim)->create();
    $super = User::factory()->for($victim)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);
    [, $key] = ApiKey::issue($super, 'w', ApiKeyPermission::Write);

    $this->withToken($key)
        ->putJson("/api/v1/groups/{$newHome->id}/packages", ['package_ids' => [$orphan->id]])
        ->assertSuccessful();

    expect($orphan->fresh()->groups()->pluck('groups.id')->all())->toBe([$newHome->id]);
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
