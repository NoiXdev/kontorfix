<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

it('shares no console/super capability and an empty scope for a plain member', function () {
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);

    $this->actingAs($member)->get('/portal')
        ->assertInertia(fn ($p) => $p
            ->where('auth.can.console', false)
            ->where('auth.can.super', false)
            ->where('scope.canSelectAll', false)
            ->where('scope.organizations', []));
});

it('shares console (not super) capability and a pinned scope for a single-org admin', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->get('/dashboard')
        ->assertInertia(fn ($p) => $p
            ->where('auth.can.console', true)
            ->where('auth.can.super', false)
            // A single-org admin is pinned to their org and cannot switch to "all".
            ->where('scope.canSelectAll', false)
            ->where('scope.active', $org->id)
            ->has('scope.organizations', 1));
});

it('shares super capability and a switchable scope for a super-admin', function () {
    Organization::factory()->count(2)->create();
    $super = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);

    $this->actingAs($super)->get('/dashboard')
        ->assertInertia(fn ($p) => $p
            ->where('auth.can.console', true)
            ->where('auth.can.super', true)
            ->where('scope.canSelectAll', true)
            ->where('scope.active', null));
});
