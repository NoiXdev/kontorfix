<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

// Same class of gap as GroupOrgScopeGuardTest and friends.
function tokenScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to revoke a foreign-org token while scoped to a different organization', function () {
    $admin = tokenScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignOrg = Organization::factory()->create();
    $foreign = RegistryToken::factory()->create(['organization_id' => $foreignOrg->id]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/tokens/{$foreign->id}")
        ->assertForbidden();

    expect(RegistryToken::find($foreign->id))->not->toBeNull();
});
