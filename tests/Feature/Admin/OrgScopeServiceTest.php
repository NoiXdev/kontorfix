<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use App\Services\Scope\OrgScope;
use Illuminate\Support\Facades\Auth;

function scopeFor(User $user): OrgScope
{
    Auth::setUser($user);

    return app(OrgScope::class);
}

it('pins a single-org admin to their own organization', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    $scope = scopeFor($admin);

    expect($scope->activeId())->toBe($org->id)
        ->and($scope->ids())->toBe([$org->id])
        ->and($scope->canSelectAll())->toBeFalse()
        ->and($scope->spansAllOrganizations())->toBeFalse();
});

it('lets a multi-org admin choose all or a specific administered org', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $admin = User::factory()->for($orgA)->create(['role' => UserRole::Admin]);
    $admin->organizations()->attach($orgB->id, ['role' => UserRole::Admin->value]);

    $scope = scopeFor($admin);

    // Default: all administered orgs, no single active id.
    expect($scope->activeId())->toBeNull()
        ->and($scope->canSelectAll())->toBeTrue()
        ->and(collect($scope->ids())->sort()->values()->all())->toBe(collect([$orgA->id, $orgB->id])->sort()->values()->all());

    // Selecting an administered org narrows the scope.
    $scope->set($orgB->id);
    expect($scope->activeId())->toBe($orgB->id)->and($scope->ids())->toBe([$orgB->id]);

    // A non-administered org is ignored (never widens access).
    $foreign = Organization::factory()->create();
    $scope->set($foreign->id);
    expect($scope->activeId())->toBe($orgB->id);

    // Reset to all.
    $scope->set(null);
    expect($scope->activeId())->toBeNull();
});

it('treats a super-admin as spanning every organization until one is picked', function () {
    $orgA = Organization::factory()->create();
    Organization::factory()->create();
    $super = User::factory()->for($orgA)->create(['role' => UserRole::Member, 'is_super_admin' => true]);

    $scope = scopeFor($super);

    expect($scope->spansAllOrganizations())->toBeTrue()
        ->and($scope->canSelectAll())->toBeTrue()
        ->and($scope->activeId())->toBeNull()
        ->and(count($scope->ids()))->toBe(Organization::count());

    // Picking a single org drops the "spans all" shortcut.
    $scope->set($orgA->id);
    expect($scope->spansAllOrganizations())->toBeFalse()
        ->and($scope->ids())->toBe([$orgA->id]);
});

it('gives a plain member no administered scope', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $scope = scopeFor($member);

    expect($scope->ids())->toBe([])
        ->and($scope->canSelectAll())->toBeFalse()
        ->and($scope->organizations())->toBe([]);
});
