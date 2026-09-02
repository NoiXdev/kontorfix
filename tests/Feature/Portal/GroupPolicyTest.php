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
