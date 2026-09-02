<?php

use App\Enums\TokenAbility;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Services\RegistryAccessService;

beforeEach(function () {
    $this->svc = app(RegistryAccessService::class);
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    $this->groupA = Group::factory()->for($this->orgA)->create();
    $this->groupB = Group::factory()->for($this->orgB)->create();
    $this->pkgA = Package::factory()->for($this->orgA)->create();
    $this->pkgB = Package::factory()->for($this->orgB)->create();
    $this->groupA->packages()->attach($this->pkgA);
    $this->groupB->packages()->attach($this->pkgB);
});

it('grants a group-scoped token access only to its group', function () {
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $this->groupA))->toBeTrue()
        ->and($this->svc->canAccessGroup($token, $this->groupB))->toBeFalse();
});

it('grants an org-wide token access to all groups of its org only', function () {
    [, $plain] = RegistryToken::issue($this->orgA, 'a', group: null);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $this->groupA))->toBeTrue()
        ->and($this->svc->canAccessGroup($token, $this->groupB))->toBeFalse();
});

it('denies anonymous access to private groups but allows public ones', function () {
    expect($this->svc->canAccessGroup(null, $this->groupA))->toBeFalse();
    $this->groupA->update(['public' => true]);
    expect($this->svc->canAccessGroup(null, $this->groupA->fresh()))->toBeTrue();
});

it('lists only packages assigned to the group and not expired', function () {
    $stillThere = Package::factory()->inOrgOf($this->groupA)->create();
    $this->groupA->packages()->attach($stillThere);
    $this->groupA->packages()->updateExistingPivot($this->pkgA->id, ['available_until' => now()->subDay()]);

    $ids = $this->svc->packagesFor($this->groupA)->pluck('id');
    expect($ids)->not->toContain($this->pkgA->id)
        ->and($ids)->toContain($stillThere->id);
});

it('denies a group-scoped token of the same org access to sibling groups', function () {
    $groupA2 = Group::factory()->for($this->orgA)->create();
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $groupA2))->toBeFalse();
});

it('grants token access to public groups regardless of org', function () {
    $this->groupB->update(['public' => true]);
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $this->groupB->fresh()))->toBeTrue();
});

it('includes packages with future or null availability', function () {
    $pkgFuture = Package::factory()->inOrgOf($this->groupA)->create();
    $this->groupA->packages()->attach($pkgFuture, ['available_until' => now()->addDay()]);

    expect($this->svc->packagesFor($this->groupA)->pluck('id'))
        ->toContain($this->pkgA->id, $pkgFuture->id);
});

it('gates package access through group access and assignment', function () {
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessPackage($token, $this->groupA, $this->pkgA))->toBeTrue()
        ->and($this->svc->canAccessPackage($token, $this->groupA, $this->pkgB))->toBeFalse()  // not assigned
        ->and($this->svc->canAccessPackage($token, $this->groupB, $this->pkgB))->toBeFalse(); // no group access
});

it('excludes expired assignments from package access', function () {
    $this->groupA->packages()->updateExistingPivot($this->pkgA->id, ['available_until' => now()->subDay()]);
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessPackage($token, $this->groupA, $this->pkgA))->toBeFalse();
});

/**
 * Both organization_id columns are database-enforced NOT NULL, so a persisted row can no
 * longer reproduce the old "ownerless group" fixture — that scenario used to be exercised
 * by a now-deleted test. canAccessGroup() and canPublishToGroup() still guard both sides
 * of the comparison against null, because they take plain models rather than a guaranteed
 * database round trip, and `null === null` must never read as "same organization". These
 * two cases build the org-wide token and the group in memory (never saved), which is the
 * only way left to construct an unset organization_id on either side, and prove the guard
 * still refuses rather than granting.
 */
it('never grants group access when both sides organization_id are unset', function () {
    $ownerlessGroup = Group::factory()->make(['organization_id' => null]);
    $orgWideToken = new RegistryToken(['organization_id' => null, 'group_id' => null]);

    expect($this->svc->canAccessGroup($orgWideToken, $ownerlessGroup))->toBeFalse();
});

it('never grants publish access when both sides organization_id are unset', function () {
    $ownerlessGroup = Group::factory()->make(['organization_id' => null]);
    $orgWideToken = new RegistryToken([
        'organization_id' => null,
        'group_id' => null,
        'ability' => TokenAbility::Publish,
    ]);

    expect($this->svc->canPublishToGroup($orgWideToken, $ownerlessGroup))->toBeFalse();
});
