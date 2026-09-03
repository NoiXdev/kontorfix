<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Queue;

/*
 * v0.8.0 made a package attachable only to registries of the organization that owns it.
 * A package owned by the operator organization and marked `shared` is the one exception:
 * it may be assigned to any registry.
 *
 * The exception stops where it would shadow. The invariant, stated over the set a registry
 * would serve AFTER a write rather than over the submission that causes it:
 *
 *   no shared package may carry the same (type, name) as a non-shared one.
 *
 * The cases below are derived from that sentence, not from the guard: for each write, the
 * post-state is enumerated over every combination of own/shared already assigned and
 * own/shared arriving, and the expectation read off the invariant. That matters, because
 * the first version of this guard compared the submission against the existing assignment
 * and so had a DIRECTION — it caught a shared package arriving over an own one and missed
 * an own one arriving over a shared package already assigned, which reaches the identical
 * end state. A test suite derived from that implementation would have shared its blind
 * spot. Each writer's post-state:
 *
 *   sync($ids)                  → the submission          (Api\V1\GroupPackageController::update)
 *   syncWithoutDetaching($ids)  → assigned ∪ submission   (Admin\GroupController::attachPackages)
 *   create + sync($ids)         → the submission          (both GroupController::store)
 *
 * Every case runs through BOTH write surfaces — the console and /api/v1. The two resolve
 * the target organization their own way and each asks the guard itself; a green console
 * test says nothing about the API path, and this repository has shipped that gap before.
 *
 * The message text is the assertion, never the exception class or the status alone: a
 * QueryException from the very collision this guard prevents would also arrive as "an
 * error", and PDOException extends RuntimeException. The three texts also distinguish
 * three situations whose remedies differ.
 */

const ALREADY_SERVED_MESSAGE = 'Diese Registry führt bereits ein eigenes Paket mit demselben Namen: composer acme/tools. '
    .'Ein geteiltes Paket darf ein eigenes nicht verdecken.';

const SUBMITTED_TOGETHER_MESSAGE = 'Diese Auswahl enthält ein eigenes und ein geteiltes Paket mit demselben Namen: '
    .'composer acme/tools. Ein geteiltes Paket darf ein eigenes nicht verdecken.';

const NAME_HELD_MESSAGE = 'Die Registry Kundenregistry führt dieses Paket bereits als geteiltes Paket. '
    .'Entfernen Sie es dort zuerst, oder wählen Sie einen anderen Namen.';

const NAME_HELD_PLURAL_MESSAGE = 'Folgende Registrys führen dieses Paket bereits als geteiltes Paket: '
    .'Erste Registry, Zweite Registry. Entfernen Sie es dort zuerst, oder wählen Sie einen anderen Namen.';

const SHARED_ALREADY_SERVED_MESSAGE = 'Diese Registry führt bereits ein geteiltes Paket mit demselben Namen: '
    .'composer acme/tools. Entfernen Sie es zuerst aus dieser Registry, bevor Sie ein eigenes Paket unter diesem '
    .'Namen zuweisen.';

/**
 * Only an operator-organization package can be marked shared (Admin\PackageController::shared),
 * so `shared === true` already implies operator ownership; the factory states both.
 */
function sharedPackage(string $name = 'acme/shared'): Package
{
    return Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['type' => 'composer', 'name' => $name, 'shared' => true]);
}

/** A super-admin's write key — the API counterpart of actingAs(superAdmin()). */
function writeKeyFor(User $user): string
{
    [, $plain] = ApiKey::issue($user, 'shared-assignment', ApiKeyPermission::Write);

    return $plain;
}

// ---------------------------------------------------------------------------------------
// Reachability: a shared package may be assigned outside its own organization; nothing else
// may.
// ---------------------------------------------------------------------------------------

it('lets a shared package be assigned to another organizations registry', function () {
    $shared = sharedPackage();
    $customer = Group::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->whereKey($shared->id)->exists())->toBeTrue();
});

it('lets a shared package be assigned to another organizations registry through the api', function () {
    $shared = sharedPackage();
    $customer = Group::factory()->create();

    $this->withToken(writeKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$shared->id]])
        ->assertOk();

    expect($customer->packages()->whereKey($shared->id)->exists())->toBeTrue();
});

it('still refuses a non-shared package of another organization', function () {
    $foreign = Package::factory()->create(['shared' => false]);
    $customer = Group::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$foreign->id]])
        ->assertForbidden();

    expect($customer->packages()->whereKey($foreign->id)->exists())->toBeFalse();
});

it('still refuses a non-shared package of another organization through the api', function () {
    $foreign = Package::factory()->create(['shared' => false]);
    $customer = Group::factory()->create();

    $this->withToken(writeKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$foreign->id]])
        ->assertForbidden();

    expect($customer->packages()->whereKey($foreign->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// syncWithoutDetaching: post-state is assigned ∪ submission, so BOTH directions collide.
// ---------------------------------------------------------------------------------------

it('refuses a shared package whose name the registry already serves', function () {
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own);

    $shared = sharedPackage('acme/tools');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertSessionHasErrors(['package_ids' => ALREADY_SERVED_MESSAGE]);

    expect($customer->packages()->whereKey($shared->id)->exists())->toBeFalse();
});

it('refuses an own package whose name the registry already serves through a shared package', function () {
    // The reverse of the case above, and the one a submission-versus-assignment rule
    // misses: the end state is identical — one registry serving both rows under one name.
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared);

    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$own->id]])
        ->assertSessionHasErrors(['package_ids' => SHARED_ALREADY_SERVED_MESSAGE]);

    expect($customer->packages()->whereKey($own->id)->exists())->toBeFalse();
});

it('refuses a shared package submitted alongside the own package it would shadow', function () {
    // Neither is assigned yet, so only the submission reveals this one.
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $shared = sharedPackage('acme/tools');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$own->id, $shared->id]])
        ->assertSessionHasErrors(['package_ids' => SUBMITTED_TOGETHER_MESSAGE]);

    expect($customer->packages()->count())->toBe(0);
});

it('lets a shared package join a registry that serves a different name', function () {
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own);

    $shared = sharedPackage('acme/other');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->count())->toBe(2);
});

it('does not treat a re-submitted shared package as colliding with itself', function () {
    // The invariant is about a shared row standing beside a NON-shared one, not about two
    // rows sharing a name: syncWithoutDetaching() leaves one row either way, and a package
    // that is both assigned and submitted appears twice in the post-state. A guard that
    // read "more than one row under this name" instead would refuse this.
    $customer = Group::factory()->create();
    $shared = sharedPackage();
    $customer->packages()->attach($shared);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->count())->toBe(1);
});

// ---------------------------------------------------------------------------------------
// sync: post-state is the submission alone, so a swap detaches the other side and is clean.
// ---------------------------------------------------------------------------------------

it('lets a shared package replace the own package of the same name through the api', function () {
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own);

    $shared = sharedPackage('acme/tools');

    // sync() detaches the own package in the same write, so the registry never serves both
    // and there is nothing to refuse.
    $this->withToken(writeKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$shared->id]])
        ->assertOk();

    expect($customer->packages()->whereKey($shared->id)->exists())->toBeTrue()
        ->and($customer->packages()->whereKey($own->id)->exists())->toBeFalse();
});

it('lets an own package replace a shared package of the same name through the api', function () {
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared);

    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);

    $this->withToken(writeKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$own->id]])
        ->assertOk();

    expect($customer->packages()->whereKey($own->id)->exists())->toBeTrue()
        ->and($customer->packages()->whereKey($shared->id)->exists())->toBeFalse();
});

it('refuses a shared package submitted alongside the own package it would shadow through the api', function () {
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $shared = sharedPackage('acme/tools');

    $this->withToken(writeKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$own->id, $shared->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['package_ids' => SUBMITTED_TOGETHER_MESSAGE]);

    expect($customer->packages()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------
// Registry creation: the registry starts empty, so its post-state is the submission.
// ---------------------------------------------------------------------------------------

it('refuses creating a registry that would serve a shared package over its own', function () {
    $org = Organization::factory()->create();
    $own = Package::factory()->for($org)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $shared = sharedPackage('acme/tools');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.store'), [
            'name' => 'Shadowed',
            'slug' => 'shadowed',
            'organization_id' => $org->id,
            'package_ids' => [$own->id, $shared->id],
        ])
        ->assertSessionHasErrors(['package_ids' => SUBMITTED_TOGETHER_MESSAGE]);

    // The refusal must not leave a half-created registry behind.
    expect(Group::where('slug', 'shadowed')->exists())->toBeFalse();
});

it('refuses creating a registry that would serve a shared package over its own through the api', function () {
    $org = Organization::factory()->create();
    $own = Package::factory()->for($org)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $shared = sharedPackage('acme/tools');

    $this->withToken(writeKeyFor(superAdmin()))
        ->postJson('/api/v1/groups', [
            'name' => 'Shadowed',
            'slug' => 'shadowed-api',
            'organization_id' => $org->id,
            'package_ids' => [$own->id, $shared->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['package_ids' => SUBMITTED_TOGETHER_MESSAGE]);

    expect(Group::where('slug', 'shadowed-api')->exists())->toBeFalse();
});

it('lets a registry be created with a shared package that shadows nothing', function () {
    $org = Organization::factory()->create();
    $shared = sharedPackage();

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.store'), [
            'name' => 'Fresh',
            'slug' => 'fresh',
            'organization_id' => $org->id,
            'package_ids' => [$shared->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Group::where('slug', 'fresh')->firstOrFail()->packages()->whereKey($shared->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------------------
// The mirror direction: package creation reaches the same end state from the other side.
// ---------------------------------------------------------------------------------------

it('refuses creating a package whose name a shared assignment already serves there', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared);

    // The unique index is (organization_id, type, name) since v0.8.0, so the customer's own
    // row is perfectly creatable — nothing but this guard stands between it and the registry
    // serving two rows under one name.
    $this->actingAs(superAdmin())
        ->post(route('admin.packages.store'), [
            'type' => 'composer',
            'name' => 'acme/tools',
            'repository_url' => 'https://git.example.com/acme/tools.git',
            'group_ids' => [$customer->id],
        ])
        ->assertSessionHasErrors(['name' => NAME_HELD_MESSAGE]);

    // Refused before the insert, so there is no orphan package either.
    expect(Package::where('name', 'acme/tools')->where('shared', false)->exists())->toBeFalse();
});

it('refuses creating a package whose name a shared assignment already serves there through the api', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared);

    $this->withToken(writeKeyFor(superAdmin()))
        ->postJson('/api/v1/packages', [
            'type' => 'composer',
            'name' => 'acme/tools',
            'repository_url' => 'https://git.example.com/acme/tools.git',
            'group_ids' => [$customer->id],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name' => NAME_HELD_MESSAGE]);

    expect(Package::where('name', 'acme/tools')->where('shared', false)->exists())->toBeFalse();
});

it('lets a package be created under a name no shared assignment in its registries holds', function () {
    Queue::fake();

    $customer = Group::factory()->create();
    // The same shared package assigned to a DIFFERENT registry must not block this create.
    sharedPackage('acme/tools')->groups()->attach(Group::factory()->create());

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.store'), [
            'type' => 'composer',
            'name' => 'acme/tools',
            'repository_url' => 'https://git.example.com/acme/tools.git',
            'group_ids' => [$customer->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->where('packages.name', 'acme/tools')->exists())->toBeTrue();
});

it('never creates a package as shared, whatever the request says', function () {
    Queue::fake();

    // Pins the assumption the two package-create paths rely on: `shared` is fillable, but
    // StorePackageRequest has no rule for it, so it never reaches $request->safe(). If
    // someone adds that rule, this test fails and points at the two store() sites, which
    // would otherwise start writing shared rows with no assignment-side check at all.
    $group = Group::factory()->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.store'), [
            'type' => 'composer',
            'name' => 'acme/sneaky',
            'repository_url' => 'https://git.example.com/acme/sneaky.git',
            'group_ids' => [$group->id],
            'shared' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(Package::where('name', 'acme/sneaky')->firstOrFail()->shared)->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// available_until: an expired assignment serves nothing, so it holds no name. The predicate
// is Group::assignedPackages(), the same one RegistryAccessService decides serving with —
// if the two disagreed, the guard would reserve names the registry does not actually serve.
// Applied to existing assignments only; a submission has no pivot row to expire yet.
// ---------------------------------------------------------------------------------------

it('lets an own package be assigned over an expired shared assignment', function () {
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared, ['available_until' => now()->subDay()]);

    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$own->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->whereKey($own->id)->exists())->toBeTrue();
});

it('still refuses an own package over a shared assignment that has not expired yet', function () {
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared, ['available_until' => now()->addDay()]);

    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$own->id]])
        ->assertSessionHasErrors(['package_ids' => SHARED_ALREADY_SERVED_MESSAGE]);

    expect($customer->packages()->whereKey($own->id)->exists())->toBeFalse();
});

it('lets a shared package be assigned over an expired own assignment', function () {
    // The same rule from the other side: an expired own assignment is not being shadowed.
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own, ['available_until' => now()->subDay()]);

    $shared = sharedPackage('acme/tools');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$shared->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->whereKey($shared->id)->exists())->toBeTrue();
});

it('lets a package be created under a name only an expired shared assignment holds', function () {
    Queue::fake();

    $customer = Group::factory()->create();
    $customer->packages()->attach(sharedPackage('acme/tools'), ['available_until' => now()->subDay()]);

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.store'), [
            'type' => 'composer',
            'name' => 'acme/tools',
            'repository_url' => 'https://git.example.com/acme/tools.git',
            'group_ids' => [$customer->id],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->packages()->where('packages.shared', false)->where('packages.name', 'acme/tools')->exists())
        ->toBeTrue();
});

it('refuses creating a package whose name a shared assignment holds until a future date', function () {
    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $customer->packages()->attach(sharedPackage('acme/tools'), ['available_until' => now()->addDay()]);

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.store'), [
            'type' => 'composer',
            'name' => 'acme/tools',
            'repository_url' => 'https://git.example.com/acme/tools.git',
            'group_ids' => [$customer->id],
        ])
        ->assertSessionHasErrors(['name' => NAME_HELD_MESSAGE]);

    expect(Package::where('name', 'acme/tools')->where('shared', false)->exists())->toBeFalse();
});

it('names every holding registry, in the plural, when more than one holds the name', function () {
    // StorePackageRequest refuses a selection spanning two organizations, so the two
    // registries have to belong to one — which is the shape an operator actually hits.
    $org = Organization::factory()->create();
    $first = Group::factory()->for($org)->create(['name' => 'Erste Registry']);
    $second = Group::factory()->for($org)->create(['name' => 'Zweite Registry']);

    $shared = sharedPackage('acme/tools');
    $first->packages()->attach($shared);
    $second->packages()->attach($shared);

    $this->actingAs(superAdmin())
        ->post(route('admin.packages.store'), [
            'type' => 'composer',
            'name' => 'acme/tools',
            'repository_url' => 'https://git.example.com/acme/tools.git',
            'group_ids' => [$first->id, $second->id],
        ])
        ->assertSessionHasErrors(['name' => NAME_HELD_PLURAL_MESSAGE]);
});

it('refuses an unrelated assignment into a registry already in the conflict state', function () {
    // Neither side of the collision arrives with this request. No write the application
    // allows can produce this state — it is reached here by writing the pivot directly — but
    // the guard still refuses, and must not tell the operator they just assigned something.
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach([$own->id, sharedPackage('acme/tools')->id]);

    $unrelated = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/other']);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$unrelated->id]])
        ->assertSessionHasErrors(['package_ids' => 'Diese Registry führt bereits ein eigenes und ein geteiltes '
            .'Paket mit demselben Namen: composer acme/tools. Entfernen Sie eines der beiden aus dieser Registry.']);

    expect($customer->packages()->whereKey($unrelated->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// One request can collide on several names at once, and one message names them all — so
// every refusal text comes in both numbers.
// ---------------------------------------------------------------------------------------

it('names every colliding package, in the plural, when one request shadows two names', function () {
    $customer = Group::factory()->create();
    $customer->packages()->attach([
        Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/one'])->id,
        Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/two'])->id,
    ]);

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one', 'shared' => true],
        ['type' => 'composer', 'name' => 'acme/two', 'shared' => true],
    ))->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => $shared->modelKeys()])
        // Both names in one message, sorted, and the sentence in the plural.
        ->assertSessionHasErrors(['package_ids' => 'Diese Registry führt bereits eigene Pakete mit denselben '
            .'Namen: composer acme/one, composer acme/two. Ein geteiltes Paket darf ein eigenes nicht verdecken.']);

    expect($customer->packages()->count())->toBe(2);
});

it('names both colliding packages in the plural when they arrive in one submission', function () {
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one'],
        ['type' => 'composer', 'name' => 'acme/two'],
    ))->create();

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one', 'shared' => true],
        ['type' => 'composer', 'name' => 'acme/two', 'shared' => true],
    ))->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), [
            'package_ids' => array_merge($own->modelKeys(), $shared->modelKeys()),
        ])
        ->assertSessionHasErrors(['package_ids' => 'Diese Auswahl enthält jeweils ein eigenes und ein geteiltes '
            .'Paket mit denselben Namen: composer acme/one, composer acme/two. '
            .'Ein geteiltes Paket darf ein eigenes nicht verdecken.']);

    expect($customer->packages()->count())->toBe(0);
});

it('names both shared packages in the plural when two own packages arrive over them', function () {
    $customer = Group::factory()->create();

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one', 'shared' => true],
        ['type' => 'composer', 'name' => 'acme/two', 'shared' => true],
    ))->create();
    $customer->packages()->attach($shared->modelKeys());

    $own = Package::factory()->inOrgOf($customer)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one'],
        ['type' => 'composer', 'name' => 'acme/two'],
    ))->create();

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => $own->modelKeys()])
        ->assertSessionHasErrors(['package_ids' => 'Diese Registry führt bereits geteilte Pakete mit denselben '
            .'Namen: composer acme/one, composer acme/two. Entfernen Sie sie zuerst aus dieser Registry, '
            .'bevor Sie eigene Pakete unter diesen Namen zuweisen.']);

    expect($customer->packages()->count())->toBe(2);
});

it('names both conflicts in the plural when a registry is already in the state twice over', function () {
    // The plural of the fallback text. Like its singular, the state is written directly:
    // no application write can produce it, which is the whole reason the text avoids
    // telling the operator what they just did.
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one'],
        ['type' => 'composer', 'name' => 'acme/two'],
    ))->create();

    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->count(2)->state(new Sequence(
        ['type' => 'composer', 'name' => 'acme/one', 'shared' => true],
        ['type' => 'composer', 'name' => 'acme/two', 'shared' => true],
    ))->create();

    $customer->packages()->attach(array_merge($own->modelKeys(), $shared->modelKeys()));

    $unrelated = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/other']);

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$unrelated->id]])
        ->assertSessionHasErrors(['package_ids' => 'Diese Registry führt bereits jeweils ein eigenes und ein '
            .'geteiltes Paket mit denselben Namen: composer acme/one, composer acme/two. '
            .'Entfernen Sie jeweils eines der beiden aus dieser Registry.']);

    expect($customer->packages()->whereKey($unrelated->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------
// The available_until EDITOR — the seventh writer, and the one that changes no pivot
// membership at all.
//
// Until Task 6 nothing in the application wrote this column, and the guard's tolerance of
// expired rows above rests on exactly that: an expired assignment stays expired, so
// permitting what it would otherwise have blocked is safe. An editor breaks the premise. An
// operator who can push a lapsed shared assignment back into the future can put the
// registry into the very state every case above refuses — one registry serving an own and a
// shared package under one name — through a request that attaches nothing.
//
// So this write asks the same question the six membership writers ask. Its post-state is
// the one syncWithoutDetaching() has, and for the same reason: what the registry serves now
// (the assignment as it stands, expiry applied) plus the row being pushed back into force.
//
// Validation refuses a date in the past, so every accepted write leaves the assignment in
// force and the guard applies to all of them — there is no branch here that could be wrong
// in one direction.
// ---------------------------------------------------------------------------------------

it('refuses to extend a lapsed shared assignment whose name the registry now serves', function () {
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared, ['available_until' => now()->subDay()]);

    // Permitted precisely because the shared assignment had lapsed (the case above).
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$customer, $shared]), ['available_until' => now()->addYear()->toDateString()])
        ->assertSessionHasErrors(['package_ids' => ALREADY_SERVED_MESSAGE]);

    // The lapse stands: the registry still serves only the customer's own package.
    expect($customer->assignedPackages()->whereKey($shared->id)->exists())->toBeFalse();
});

it('refuses to make a lapsed shared assignment open-ended when the name is taken', function () {
    // Clearing the date is the same act with no date in it, and it must not be the way
    // around the refusal above.
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared, ['available_until' => now()->subDay()]);

    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$customer, $shared]), ['available_until' => null])
        ->assertSessionHasErrors(['package_ids' => ALREADY_SERVED_MESSAGE]);

    expect($customer->assignedPackages()->whereKey($shared->id)->exists())->toBeFalse();
});

it('refuses to extend a lapsed own assignment whose name a shared assignment now serves', function () {
    // The mirror direction. The guard is stated over the resulting set and so has none of
    // its own, but the editor could still have been wired to ask only about shared rows.
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own, ['available_until' => now()->subDay()]);

    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$customer, $own]), ['available_until' => now()->addYear()->toDateString()])
        ->assertSessionHasErrors(['package_ids' => SHARED_ALREADY_SERVED_MESSAGE]);

    expect($customer->assignedPackages()->whereKey($own->id)->exists())->toBeFalse();
});

it('lets a lapsed shared assignment be extended when nothing else holds the name', function () {
    // The refusals above must not be a blanket "no": a time-limited share whose date the
    // operator wants to move is the ordinary case this dialog exists for.
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared, ['available_until' => now()->subDay()]);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$customer, $shared]), ['available_until' => now()->addYear()->toDateString()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->assignedPackages()->whereKey($shared->id)->exists())->toBeTrue();
});

it('does not treat an in-force assignment as colliding with itself when its date moves', function () {
    // The package is in the registry's current assignment AND in the submission, so it
    // appears twice in the post-state. Two rows of the same package are not two packages.
    $customer = Group::factory()->create();
    $shared = sharedPackage('acme/tools');
    $customer->packages()->attach($shared, ['available_until' => now()->addDay()]);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$customer, $shared]), ['available_until' => now()->addYear()->toDateString()])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($customer->assignedPackages()->whereKey($shared->id)->exists())->toBeTrue();
});
