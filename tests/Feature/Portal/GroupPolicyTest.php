<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Policies\GroupPolicy;

/**
 * groups.organization_id is database-enforced NOT NULL, so a persisted row can never
 * reach GroupPolicy::view() with an unset organization_id. But the policy takes a plain
 * model, not a guaranteed database round trip, and belongsToOrganization() takes a
 * non-nullable string — passing it a null organization_id would throw a TypeError rather
 * than gracefully deny, turning an unset organization on an in-memory Group into an
 * uncaught exception instead of "not viewable". Built in memory (never saved), which is
 * the only way left to construct an unset organization_id on a Group.
 */
it('refuses to view a portal-enabled group with no organization instead of throwing', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create();

    $ownerlessGroup = Group::factory()->make(['organization_id' => null, 'portal_enabled' => true]);

    expect((new GroupPolicy)->view($user, $ownerlessGroup))->toBeFalse();
});

/*
 * `portal_enabled` inside the policy itself, called directly rather than through a route.
 *
 * The three portal paths that authorize 'view' — RegistryController::show(), showPackage()
 * and TokenController::store() — each state `abort_unless($group->portal_enabled, 404)`
 * before they authorize, because enforcement of a surface property belongs in the controller
 * where every population gets one answer (Task 4). That leaves the policy's own clause with
 * no traffic: deleting it used to redden nothing at all, and a comment asking the next reader
 * not to remove it is not a test.
 *
 * It is kept because the statement is true at the policy's own level of abstraction — "not if
 * the portal hides it" is a property of this policy, not of whichever caller happens to ask
 * first — and a defence that is true at its own level and directly pinned is worth keeping,
 * where an unpinned one is not. Reached the only way left to reach it: by calling the policy.
 *
 * Both directions, in two tests rather than one, so each can fail on its own.
 */
it('refuses to view a group the portal does not show', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create();
    $hidden = Group::factory()->for($org)->create(['portal_enabled' => false]);

    // A member of the group's OWN organization: every other clause in view() would grant.
    expect((new GroupPolicy)->view($user, $hidden))->toBeFalse();
});

it('still allows viewing a group the portal does show', function () {
    // The present half. A policy that refused this member outright, or that read some other
    // column, would satisfy the case above on its own.
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create();
    $shown = Group::factory()->for($org)->create(['portal_enabled' => true]);

    expect((new GroupPolicy)->view($user, $shown))->toBeTrue();
});
