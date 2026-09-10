<?php

use App\Enums\UserRole;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\User;

// A super-admin (or operator-org admin, grandfathered) administers every organization, so
// `assertAdministersOrg()` alone would let one who has deliberately scoped the console down
// to `$ownOrg` still reach a credential owned by a completely unrelated organization. The
// guard must ask the ACTIVE SCOPE, not just "does this account administer that org at all".
function credentialScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to edit a foreign-org git credential while scoped to a different organization', function () {
    $admin = credentialScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = GitCredential::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->get("/admin/git-credentials/{$foreign->id}/edit")
        ->assertForbidden();
});

it('refuses to update a foreign-org git credential while scoped to a different organization', function () {
    $admin = credentialScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = GitCredential::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->put("/admin/git-credentials/{$foreign->id}", ['name' => 'renamed', 'provider' => 'github'])
        ->assertForbidden();

    expect($foreign->fresh()->name)->toBe('theirs');
});

it('refuses to test a foreign-org git credential while scoped to a different organization', function () {
    $admin = credentialScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = GitCredential::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->postJson("/admin/git-credentials/{$foreign->id}/test", ['repository_url' => 'https://github.com/acme/repo'])
        ->assertForbidden();
});

it('refuses to delete a foreign-org git credential while scoped to a different organization', function () {
    $admin = credentialScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreign = GitCredential::factory()->create(['name' => 'theirs']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/git-credentials/{$foreign->id}")
        ->assertForbidden();

    expect(GitCredential::find($foreign->id))->not->toBeNull();
});
