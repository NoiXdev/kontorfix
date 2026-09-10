<?php

use App\Enums\UserRole;
use App\Models\MirrorSource;
use App\Models\Organization;
use App\Models\User;

// Same reasoning as GitCredentialOrgScopeGuardTest: a super-admin (or grandfathered
// operator-org admin) administers every organization, so the scope-unaware
// `assertAdministersOrg()` would let one who has scoped the console down to `$ownOrg` still
// reach a mirror source owned by an unrelated organization.
function mirrorSourceScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to edit a foreign-org mirror source while scoped to a different organization', function () {
    $admin = mirrorSourceScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = MirrorSource::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->get("/admin/mirror-sources/{$foreign->id}/edit")
        ->assertForbidden();
});

it('refuses to update a foreign-org mirror source while scoped to a different organization', function () {
    $admin = mirrorSourceScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = MirrorSource::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->put("/admin/mirror-sources/{$foreign->id}", [
            'name' => 'renamed', 'type' => 'composer', 'url' => 'https://repo.example.test',
        ])
        ->assertForbidden();

    expect($foreign->fresh()->name)->toBe('theirs');
});

it('refuses to delete a foreign-org mirror source while scoped to a different organization', function () {
    $admin = mirrorSourceScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = MirrorSource::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/mirror-sources/{$foreign->id}")
        ->assertForbidden();

    expect(MirrorSource::find($foreign->id))->not->toBeNull();
});
