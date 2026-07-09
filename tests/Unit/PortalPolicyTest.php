<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a member view and manage only their own org resources', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    $member = User::factory()->for($orgA)->create(['role' => UserRole::Member]);

    $ownGroup = Group::factory()->for($orgA)->create();
    $otherGroup = Group::factory()->for($orgB)->create();
    // Persönliches Token des Members: löschbar.
    $ownToken = RegistryToken::factory()->for($orgA)->create(['user_id' => $member->id]);
    // Org-geteiltes Token (ohne Besitzer): für Member NICHT löschbar.
    $sharedToken = RegistryToken::factory()->for($orgA)->create(['user_id' => null]);
    $otherToken = RegistryToken::factory()->for($orgB)->create();

    expect($member->can('view', $ownGroup))->toBeTrue();
    expect($member->can('view', $otherGroup))->toBeFalse();
    expect($member->can('delete', $ownToken))->toBeTrue();
    expect($member->can('delete', $sharedToken))->toBeFalse();
    expect($member->can('delete', $otherToken))->toBeFalse();
});

it('lets an operator admin view any group', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->for($operator)->create(['role' => UserRole::Admin]);
    $foreignGroup = Group::factory()->for(Organization::factory()->create())->create();

    expect($admin->can('view', $foreignGroup))->toBeTrue();
});
