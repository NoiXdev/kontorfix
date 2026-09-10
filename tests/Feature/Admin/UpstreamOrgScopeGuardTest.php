<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\User;

// Same class of gap as GroupOrgScopeGuardTest/DomainOrgScopeGuardTest.
function upstreamScopeSuper(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('refuses to create an upstream on a foreign-org registry while scoped to a different organization', function () {
    $admin = upstreamScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->post('/admin/upstreams', [
            'group_id' => $foreignGroup->id, 'type' => 'composer', 'url' => 'https://repo.evil.test', 'policy' => 'proxy',
        ])
        ->assertForbidden();

    expect(Upstream::where('group_id', $foreignGroup->id)->exists())->toBeFalse();
});

it('refuses to view the edit page of a foreign-org upstream while scoped to a different organization', function () {
    $admin = upstreamScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();
    $foreign = Upstream::factory()->for($foreignGroup)->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->get("/admin/upstreams/{$foreign->id}/edit")
        ->assertForbidden();
});

it('refuses to update a foreign-org upstream while scoped to a different organization', function () {
    $admin = upstreamScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();
    $foreign = Upstream::factory()->for($foreignGroup)->create(['url' => 'https://repo.example.test']);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->put("/admin/upstreams/{$foreign->id}", [
            'type' => 'composer', 'url' => 'https://repo.example.test', 'policy' => 'proxy',
        ])
        ->assertForbidden();

    expect($foreign->fresh()->url)->toBe('https://repo.example.test');
});

it('refuses to delete a foreign-org upstream while scoped to a different organization', function () {
    $admin = upstreamScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();
    $foreign = Upstream::factory()->for($foreignGroup)->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/upstreams/{$foreign->id}")
        ->assertForbidden();

    expect(Upstream::find($foreign->id))->not->toBeNull();
});
