<?php

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
    $this->pkgA = Package::factory()->create();
    $this->pkgB = Package::factory()->create();
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
    $stillThere = Package::factory()->create();
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

it('denies org-wide tokens access to ownerless groups', function () {
    $orphan = Group::factory()->create(['organization_id' => null]);
    [, $plain] = RegistryToken::issue($this->orgA, 'a', group: null);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $orphan))->toBeFalse();
});

it('grants token access to public groups regardless of org', function () {
    $this->groupB->update(['public' => true]);
    [, $plain] = RegistryToken::issue($this->orgA, 'a', $this->groupA);
    $token = RegistryToken::findByPlainText($plain);

    expect($this->svc->canAccessGroup($token, $this->groupB->fresh()))->toBeTrue();
});

it('includes packages with future or null availability', function () {
    $pkgFuture = Package::factory()->create();
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
