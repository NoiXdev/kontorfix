<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('counts only administered orgs, ignoring plain memberships', function () {
    $home = Organization::factory()->create();
    $adminOrg = Organization::factory()->create();
    $memberOrg = Organization::factory()->create();

    $user = User::factory()->for($home)->create(['role' => UserRole::Admin]);
    $user->organizations()->attach($adminOrg->id, ['role' => UserRole::Admin->value]);
    $user->organizations()->attach($memberOrg->id, ['role' => UserRole::Member->value]);

    expect(collect($user->administeredOrganizationIds())->sort()->values()->all())
        ->toBe(collect([$home->id, $adminOrg->id])->sort()->values()->all());

    expect($user->administers($home->id))->toBeTrue()
        ->and($user->administers($adminOrg->id))->toBeTrue()
        ->and($user->administers($memberOrg->id))->toBeFalse()
        ->and($user->roleIn($memberOrg->id))->toBe(UserRole::Member)
        ->and($user->roleIn(Organization::factory()->create()->id))->toBeNull();
});

it('treats a maintainer as administering their home org but not the console-only member orgs', function () {
    $home = Organization::factory()->create();
    $user = User::factory()->for($home)->create(['role' => UserRole::Maintainer]);

    expect($user->canAdministerConsole())->toBeTrue()
        ->and($user->administers($home->id))->toBeTrue();
});

it('treats a super-admin as admin of every organization', function () {
    $super = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member, 'is_super_admin' => true]);
    $anyOrg = Organization::factory()->create();

    expect($super->roleIn($anyOrg->id))->toBe(UserRole::Admin)
        ->and($super->administers($anyOrg->id))->toBeTrue()
        ->and($super->canAdministerConsole())->toBeTrue();
});

it('gives a plain member no console access and a null role outside their org', function () {
    $home = Organization::factory()->create();
    $member = User::factory()->for($home)->create(['role' => UserRole::Member]);

    expect($member->canAdministerConsole())->toBeFalse()
        ->and($member->administeredOrganizationIds())->toBe([])
        ->and($member->roleIn($home->id))->toBe(UserRole::Member)
        ->and($member->administers($home->id))->toBeFalse();
});

it('forbids a plain member from switching the admin scope', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $org = Organization::factory()->create();

    $this->actingAs($member)->post('/admin/scope', ['organization_id' => $org->id])->assertForbidden();
});
