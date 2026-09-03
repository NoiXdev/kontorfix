<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/*
 * v0.8.0 made a package attachable only to registries of the organization that owns it.
 * A package owned by the operator organization and marked `shared` is the one exception:
 * it may be assigned to any registry.
 *
 * The exception stops where it would shadow: a customer's own package always wins over a
 * shared one of the same name, so an assignment that would put both names into one
 * registry is refused rather than accepted and silently resolved one way or the other.
 *
 * Every case below runs through BOTH write surfaces — the console (`attachPackages`) and
 * `/api/v1` (`GroupPackageController::update`). The two resolve the target organization
 * their own way and each calls the guard itself; a green console test says nothing about
 * the API path, and this repository has shipped that exact gap before.
 */

/*
 * The message text is the assertion, not just the exception class or the error key: a
 * QueryException from the very collision this guard prevents would also arrive as "an
 * error", and PDOException extends RuntimeException — a class-only assertion cannot tell a
 * clean refusal from the database failure the refusal exists to prevent. The two texts also
 * distinguish the two situations, whose remedies differ.
 */
const ALREADY_SERVED_MESSAGE = 'Diese Registry führt bereits ein eigenes Paket mit demselben Namen: composer acme/tools. '
    .'Ein geteiltes Paket darf ein eigenes nicht verdecken.';

const SUBMITTED_TOGETHER_MESSAGE = 'Diese Auswahl enthält ein eigenes und ein geteiltes Paket mit demselben Namen: '
    .'composer acme/tools. Ein geteiltes Paket darf ein eigenes nicht verdecken.';

function sharedPackage(string $name = 'acme/shared'): Package
{
    // Only an operator-organization package can be marked shared (Admin\PackageController::shared),
    // so `shared === true` already implies operator ownership; the factory states both.
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

it('refuses a shared package whose name the registry already serves through the api', function () {
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $customer->packages()->attach($own);

    $shared = sharedPackage('acme/tools');

    $this->withToken(writeKeyFor(superAdmin()))
        ->putJson("/api/v1/groups/{$customer->id}/packages", ['package_ids' => [$shared->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['package_ids' => ALREADY_SERVED_MESSAGE]);

    // sync() would have replaced the whole set; the refusal must leave it untouched.
    expect($customer->packages()->whereKey($shared->id)->exists())->toBeFalse()
        ->and($customer->packages()->whereKey($own->id)->exists())->toBeTrue();
});

it('refuses a shared package submitted alongside the own package it would shadow', function () {
    // The collision the registry's current contents cannot reveal: both packages arrive in
    // the same request, so "already serves" is only true once this very assignment lands.
    $customer = Group::factory()->create();
    $own = Package::factory()->inOrgOf($customer)->create(['type' => 'composer', 'name' => 'acme/tools']);
    $shared = sharedPackage('acme/tools');

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $customer), ['package_ids' => [$own->id, $shared->id]])
        ->assertSessionHasErrors(['package_ids' => SUBMITTED_TOGETHER_MESSAGE]);

    expect($customer->packages()->count())->toBe(0);
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

it('refuses creating a registry that would serve a shared package over its own', function () {
    // The third and fourth writers of `group_package`: registry creation seeds the pivot
    // too, and a brand-new registry has no contents to compare against — only the
    // submission itself can reveal this collision.
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
