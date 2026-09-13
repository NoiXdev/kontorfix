<?php

// Turning a registry public is a single boolean on an ordinary update, and `public` short-
// circuits RegistryAccessService::canAccessGroup() for anonymous callers. A customer admin
// could therefore publish the OPERATOR's shared packages to the anonymous internet with one
// PUT — and not just their metadata: ComposerController::dist() and
// NpmController::respondTarball() stream artifact BYTES on that same unauthenticated path.
//
// docs/development.md states distribution of shared packages is "the operator's decision,
// not theirs". This is the seam where that sentence was not true.

use App\Enums\ApiKeyPermission;
use App\Enums\PackageType;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

function sharedGuardWriteKey(User $user): string
{
    [, $plain] = ApiKey::issue($user, 'w', ApiKeyPermission::Write);

    return $plain;
}

function sharedPackageInRegistry(Group $group, string $name = 'acme/lizenz'): Package
{
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create([
        'type' => PackageType::Composer, 'name' => $name, 'shared' => true,
    ]);
    $group->packages()->attach($package);

    return $package;
}

it('refuses a customer admin turning a registry public while it serves shared packages', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['public' => false, 'slug' => 'kunde']);
    sharedPackageInRegistry($group);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => $group->name, 'slug' => 'kunde', 'public' => true, 'portal_enabled' => false,
    ])->assertSessionHasErrors('public');

    expect($group->fresh()->public)->toBeFalse();
});

it('refuses the same flip through the api', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['public' => false]);
    sharedPackageInRegistry($group);

    $this->withToken(sharedGuardWriteKey($admin))
        ->putJson(route('api.v1.groups.update', $group), ['name' => $group->name, 'public' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors('public');

    expect($group->fresh()->public)->toBeFalse();
});

it('still lets a customer admin publish a registry that holds only their own packages', function () {
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['public' => false, 'slug' => 'kunde']);
    $group->packages()->attach(Package::factory()->for($org)->create(['shared' => false]));

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => $group->name, 'slug' => 'kunde', 'public' => true, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->public)->toBeTrue();
});

it('lets the operator make that call, because the packages are theirs to distribute', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create(['public' => false, 'slug' => 'kunde']);
    sharedPackageInRegistry($group);

    $this->actingAs(superAdmin())->put(route('admin.groups.update', $group), [
        'name' => $group->name, 'slug' => 'kunde', 'public' => true, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->public)->toBeTrue();
});

it('ignores a shared assignment that has already lapsed', function () {
    // An expired assignment is not served, so it is not published either — the guard asks
    // the same question the read path asks (Group::assignedPackages), not a broader one.
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['public' => false, 'slug' => 'kunde']);
    $package = sharedPackageInRegistry($group);
    $group->packages()->updateExistingPivot($package->id, ['available_until' => now()->subDay()]);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => $group->name, 'slug' => 'kunde', 'public' => true, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->public)->toBeTrue();
});

it('leaves an already-public registry editable without re-litigating the flag', function () {
    // The guard is on the TRANSITION. A registry the operator deliberately made public
    // must stay editable by its customer for everything else.
    $org = Organization::factory()->create();
    $admin = adminOf($org);
    $group = Group::factory()->for($org)->create(['public' => true, 'slug' => 'kunde']);
    sharedPackageInRegistry($group);

    $this->actingAs($admin)->put(route('admin.groups.update', $group), [
        'name' => 'Neuer Name', 'slug' => 'kunde', 'public' => true, 'portal_enabled' => false,
    ])->assertSessionHasNoErrors();

    expect($group->fresh()->name)->toBe('Neuer Name')
        ->and($group->fresh()->public)->toBeTrue();
});

it('applies the operator exemption on the api too, where the caller is an api key', function () {
    // The exemption reads $this->user(), and under key auth that resolver is installed by
    // AuthenticateApiKey rather than by the session guard. If it were not, a super-admin
    // would be refused on one surface and allowed on the other.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create(['public' => false]);
    sharedPackageInRegistry($group);

    $this->withToken(sharedGuardWriteKey(superAdmin()))
        ->putJson(route('api.v1.groups.update', $group), ['name' => $group->name, 'public' => true])
        ->assertOk();

    expect($group->fresh()->public)->toBeTrue();
});
