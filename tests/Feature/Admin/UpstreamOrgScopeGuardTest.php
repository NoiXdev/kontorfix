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

it('answers 403, not 422, for a foreign-org upstream holding a credential when scoped elsewhere (no oracle)', function () {
    // Before the fix, UpdateUpstreamRequest::authorize() asked the scope-unaware
    // `administers()`, which is true for a super-admin regardless of the active scope — so
    // it let the request through to withValidator(), which reads the upstream's stored
    // auth_token and adds a 422 naming `auth_token` on a host change that would drop it.
    // A scoped-out caller could tell whether someone else's upstream carries a credential
    // from the status code alone (422 = has one, 403 = does not) without ever being allowed
    // to see it. authorize() must now refuse before validation runs, regardless of what the
    // upstream holds.
    $admin = upstreamScopeSuper();
    $ownOrg = Organization::factory()->create();
    $foreignGroup = Group::factory()->create();
    $foreign = Upstream::factory()->for($foreignGroup)->create([
        'url' => 'https://old-host.example.test',
        'auth_token' => 'super-secret-token',
    ]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->put("/admin/upstreams/{$foreign->id}", [
            // Host change, no auth_token/remove_auth_token — exactly the shape that trips
            // the "token would be silently dropped" rule in withValidator() if ever reached.
            'type' => 'composer', 'url' => 'https://new-host.example.test', 'policy' => 'proxy',
        ])
        ->assertForbidden();

    expect($foreign->fresh()->url)->toBe('https://old-host.example.test')
        ->and($foreign->fresh()->auth_token)->toBe('super-secret-token');
});
