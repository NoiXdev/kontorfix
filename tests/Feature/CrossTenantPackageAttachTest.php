<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/*
 * Packages carry no organization column — they belong to an organization through the
 * registries they are attached to. Every endpoint that accepts `package_ids` must
 * therefore refuse ids that are already owned elsewhere, or a customer-org admin could
 * pull a foreign package into their own registry and inherit write access to it
 * (repository_url takeover, deletion).
 */

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['name' => 'Org A']);
    $this->orgB = Organization::factory()->create(['name' => 'Org B']);
    // Admin of Org A only — a per-organization role, not a global super-admin.
    $this->adminA = User::factory()->for($this->orgA)->create(['role' => UserRole::Admin]);
    $this->groupA = Group::factory()->for($this->orgA)->create();
    $this->groupB = Group::factory()->for($this->orgB)->create();

    // A package that already belongs to Org B through Org B's registry.
    $this->foreignPackage = Package::factory()->create(['name' => 'b/theirs']);
    $this->groupB->packages()->attach($this->foreignPackage->id);

    [, $this->apiKey] = ApiKey::issue($this->adminA, 'w', ApiKeyPermission::Write);
});

it('forbids creating a registry that pulls in a foreign orgs package', function () {
    $this->actingAs($this->adminA)->post('/admin/groups', [
        'name' => 'Hijack',
        'slug' => 'hijack',
        'package_ids' => [$this->foreignPackage->id],
    ])->assertForbidden();

    expect(Group::where('slug', 'hijack')->exists())->toBeFalse()
        ->and($this->foreignPackage->groups()->count())->toBe(1);
});

it('forbids attaching a foreign orgs package to the own registry', function () {
    $this->actingAs($this->adminA)
        ->post("/admin/groups/{$this->groupA->id}/packages", ['package_ids' => [$this->foreignPackage->id]])
        ->assertForbidden();

    expect($this->groupA->packages()->count())->toBe(0);
});

it('forbids creating a registry with a foreign orgs package through the api', function () {
    $this->withToken($this->apiKey)->postJson('/api/v1/groups', [
        'name' => 'Hijack',
        'slug' => 'hijack-api',
        'package_ids' => [$this->foreignPackage->id],
    ])->assertForbidden();

    expect(Group::where('slug', 'hijack-api')->exists())->toBeFalse()
        ->and($this->foreignPackage->groups()->count())->toBe(1);
});

it('forbids syncing a foreign orgs package into the own registry through the api', function () {
    $this->withToken($this->apiKey)
        ->putJson("/api/v1/groups/{$this->groupA->id}/packages", ['package_ids' => [$this->foreignPackage->id]])
        ->assertForbidden();

    expect($this->groupA->packages()->count())->toBe(0);
});

it('still allows attaching an unclaimed package and one from the own organization', function () {
    // Freshly created packages are attached to no registry yet — the package picker
    // creates them inline and attaches them in the same flow, so they must stay allowed.
    $orphan = Package::factory()->create(['name' => 'a/fresh']);
    $own = Package::factory()->create(['name' => 'a/mine']);
    $this->groupA->packages()->attach($own->id);

    $this->actingAs($this->adminA)
        ->post("/admin/groups/{$this->groupA->id}/packages", ['package_ids' => [$orphan->id, $own->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->groupA->packages()->count())->toBe(2);
});

it('allows a super admin to share a package across organizations', function () {
    $super = User::factory()->for($this->orgA)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);

    $this->actingAs($super)
        ->post("/admin/groups/{$this->groupA->id}/packages", ['package_ids' => [$this->foreignPackage->id]])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($this->groupA->packages()->count())->toBe(1);
});
