<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

it('forbids a member from revoking another members personal token in the same org', function () {
    $org = Organization::factory()->create();
    $a = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $b = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    [$tokenOfA] = RegistryToken::issue($org, 'a-token', $group, owner: $a);

    $this->actingAs($b)->delete(route('portal.tokens.destroy', [$org->slug, $tokenOfA->id]))->assertForbidden();
    expect(RegistryToken::find($tokenOfA->id))->not->toBeNull();
});

it('allows a member to revoke their own personal token', function () {
    $org = Organization::factory()->create();
    $a = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    [$tokenOfA] = RegistryToken::issue($org, 'a-token', $group, owner: $a);

    $this->actingAs($a)->from("/c/{$org->slug}/registries")
        ->delete(route('portal.tokens.destroy', [$org->slug, $tokenOfA->id]))->assertRedirect();
    expect(RegistryToken::find($tokenOfA->id))->toBeNull();
});

it('forbids a member from revoking an org-shared token without owner', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $shared = RegistryToken::factory()->for($org)->create(['user_id' => null]);

    $this->actingAs($member)->delete(route('portal.tokens.destroy', [$org->slug, $shared->id]))->assertForbidden();
    expect(RegistryToken::find($shared->id))->not->toBeNull();
});

it('allows an admin to revoke an org-shared token without owner', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $shared = RegistryToken::factory()->for($org)->create(['user_id' => null]);

    $this->actingAs($admin)->from("/c/{$org->slug}/registries")
        ->delete(route('portal.tokens.destroy', [$org->slug, $shared->id]))->assertRedirect();
    expect(RegistryToken::find($shared->id))->toBeNull();
});

it('refuses to revoke a token of another organization through this portals address', function () {
    // The URL's coherence rule, the one store() got and destroy() did not. The caller is an
    // admin of BOTH organizations, so nothing here is about permission — RegistryTokenPolicy
    // says yes, and the same account revokes the same token one line further down through the
    // address that names its owner. What is refused is the address: /c/{orgA} acting on a token
    // of orgB makes the segment mean nothing.
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();
    $admin = User::factory()->for($a)->create(['role' => UserRole::Admin]);
    $admin->organizations()->attach($b->id, ['role' => 'admin']);
    $tokenOfB = RegistryToken::factory()->for($b)->create(['user_id' => null]);

    // The refusal, and what did NOT happen: a 403 that had already deleted the row would be no
    // refusal at all, and the status alone cannot tell the two apart.
    $this->actingAs($admin)->delete(route('portal.tokens.destroy', [$a->slug, $tokenOfB->id]))
        ->assertForbidden();
    expect(RegistryToken::find($tokenOfB->id))->not->toBeNull();

    // The SAME caller and the SAME token through the address that names its organization. The
    // refusal above is otherwise indistinguishable from a policy that simply says no, and this
    // is what makes it a statement about the URL.
    $this->actingAs($admin)->from("/c/{$b->slug}/registries")
        ->delete(route('portal.tokens.destroy', [$b->slug, $tokenOfB->id]))
        ->assertRedirect();
    expect(RegistryToken::find($tokenOfB->id))->toBeNull();
});

it('only lists the current members own tokens on the portal registry page', function () {
    $org = Organization::factory()->create();
    $a = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $b = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    RegistryToken::issue($org, 'a-token', $group, owner: $a);
    RegistryToken::issue($org, 'b-token', $group, owner: $b);

    $this->actingAs($a)->get(route('portal.registries.show', [$org->slug, $group->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tokens', 1)->where('tokens.0.name', 'a-token'));
});
