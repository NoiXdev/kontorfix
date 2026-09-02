<?php

// `packages.name` is unique per type across the whole instance, but a package belongs to an
// organization only through the registries it is attached to. Creating one with no
// `group_ids` therefore burned the name instance-wide and produced a row invisible to its
// own creator — every package listing joins through `groups`. Since orphans are attachable
// only by a super-admin, the creating organization could not recover it either: a careless
// operator bricks their own name, a hostile one squats a competitor's. Both create paths
// share StorePackageRequest, so the registry is now mandatory on both.

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

function creatingAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

it('refuses a web create that names no registry', function () {
    $org = Organization::factory()->create(['is_operator' => true]);

    $this->actingAs(creatingAdmin($org))->post('/admin/packages', [
        'type' => 'npm', 'name' => '@acme/limbo', 'source_mode' => 'publish',
    ])->assertSessionHasErrors('group_ids');

    expect(Package::where('name', '@acme/limbo')->exists())->toBeFalse();
});

it('refuses a web create whose registry list is empty', function () {
    $org = Organization::factory()->create(['is_operator' => true]);

    $this->actingAs(creatingAdmin($org))->post('/admin/packages', [
        'type' => 'npm', 'name' => '@acme/empty', 'source_mode' => 'publish', 'group_ids' => [],
    ])->assertSessionHasErrors('group_ids');

    expect(Package::where('name', '@acme/empty')->exists())->toBeFalse();
});

it('still creates a web package that names a registry', function () {
    // Reachability anchor: same actor, same route, same payload but for `group_ids`.
    // Proves the rejection above comes from the new rule and not from the type gate, the
    // name regex, the uniqueness rule or the operator boundary.
    $org = Organization::factory()->create(['is_operator' => true]);
    $group = Group::factory()->for($org)->create();

    $this->actingAs(creatingAdmin($org))->post('/admin/packages', [
        'type' => 'npm', 'name' => '@acme/homed', 'source_mode' => 'publish',
        'group_ids' => [$group->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Package::where('name', '@acme/homed')->sole()->groups()->pluck('groups.id')->all())
        ->toBe([$group->id]);
});

it('refuses an api create that names no registry', function () {
    $org = Organization::factory()->create(['is_operator' => true]);
    [, $key] = ApiKey::issue(creatingAdmin($org), 'w', ApiKeyPermission::Write);

    $this->withToken($key)->postJson('/api/v1/packages', [
        'type' => 'npm', 'name' => '@acme/api-limbo', 'source_mode' => 'publish',
    ])->assertStatus(422)->assertJsonValidationErrors('group_ids');

    expect(Package::where('name', '@acme/api-limbo')->exists())->toBeFalse();
});

it('still creates an api package that names a registry', function () {
    // Reachability anchor for the API case.
    $org = Organization::factory()->create(['is_operator' => true]);
    $group = Group::factory()->for($org)->create();
    [, $key] = ApiKey::issue(creatingAdmin($org), 'w', ApiKeyPermission::Write);

    $this->withToken($key)->postJson('/api/v1/packages', [
        'type' => 'npm', 'name' => '@acme/api-homed', 'source_mode' => 'publish',
        'group_ids' => [$group->id],
    ])->assertCreated();

    expect(Package::where('name', '@acme/api-homed')->sole()->groups()->pluck('groups.id')->all())
        ->toBe([$group->id]);
});

it('lets a super-admin see and re-home an orphan left behind by an earlier create', function () {
    // The recovery path for the packages already in limbo: a super-admin's scope spans
    // every organization, so orphans appear in the package listing and the attach guard
    // stands down for them. No migration deletes or reassigns anything.
    $org = Organization::factory()->create(['is_operator' => true]);
    $home = Group::factory()->for($org)->create();
    $orphan = Package::factory()->for($org)->create(['name' => 'acme/already-orphaned']);

    $super = User::factory()->for($org)->create(['role' => UserRole::Admin, 'is_super_admin' => true]);

    $this->actingAs($super)->get('/admin/packages')
        ->assertInertia(fn ($page) => $page->where(
            'packages.data',
            fn ($rows) => collect($rows)->contains('name', 'acme/already-orphaned'),
        ));

    $this->actingAs($super)->from('/admin/groups')
        ->post("/admin/groups/{$home->id}/packages", ['package_ids' => [$orphan->id]])
        ->assertRedirect();

    expect($orphan->fresh()->groups()->pluck('groups.id')->all())->toBe([$home->id]);
});
