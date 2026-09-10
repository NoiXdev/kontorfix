<?php

use App\Enums\UserRole;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

// Same class of gap as GroupOrgScopeGuardTest: a super-admin (or grandfathered
// operator-org admin) administers every organization at once, so the scope-unaware
// `assertAdministersGroup()`/`assertAdministersOrg()` would let one who has deliberately
// scoped the console down to `$ownOrg` still attach or remove a domain on a completely
// unrelated organization's registry.
function domainScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to create a domain on a foreign-org registry while scoped to a different organization', function () {
    $admin = domainScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->post('/admin/domains', ['group_id' => $foreignGroup->id, 'hostname' => 'scoped-out.example.test'])
        ->assertForbidden();

    expect(Domain::where('hostname', 'scoped-out.example.test')->exists())->toBeFalse();
});

it('refuses to delete a foreign-org domain while scoped to a different organization', function () {
    $admin = domainScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();
    $foreignDomain = Domain::factory()->for($foreignGroup)->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/domains/{$foreignDomain->id}")
        ->assertForbidden();

    expect(Domain::find($foreignDomain->id))->not->toBeNull();
});
