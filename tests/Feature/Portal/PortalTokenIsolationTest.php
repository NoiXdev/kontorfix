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

    $this->actingAs($b)->delete(route('portal.tokens.destroy', $tokenOfA->id))->assertForbidden();
    expect(RegistryToken::find($tokenOfA->id))->not->toBeNull();
});

it('allows a member to revoke their own personal token', function () {
    $org = Organization::factory()->create();
    $a = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    [$tokenOfA] = RegistryToken::issue($org, 'a-token', $group, owner: $a);

    $this->actingAs($a)->from('/portal')
        ->delete(route('portal.tokens.destroy', $tokenOfA->id))->assertRedirect();
    expect(RegistryToken::find($tokenOfA->id))->toBeNull();
});

it('forbids a member from revoking an org-shared token without owner', function () {
    $org = Organization::factory()->create();
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $shared = RegistryToken::factory()->for($org)->create(['user_id' => null]);

    $this->actingAs($member)->delete(route('portal.tokens.destroy', $shared->id))->assertForbidden();
    expect(RegistryToken::find($shared->id))->not->toBeNull();
});

it('allows an admin to revoke an org-shared token without owner', function () {
    $org = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $shared = RegistryToken::factory()->for($org)->create(['user_id' => null]);

    $this->actingAs($admin)->from('/portal')
        ->delete(route('portal.tokens.destroy', $shared->id))->assertRedirect();
    expect(RegistryToken::find($shared->id))->toBeNull();
});

it('only lists the current members own tokens on the portal registry page', function () {
    $org = Organization::factory()->create();
    $a = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $b = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $group = Group::factory()->for($org)->create();
    RegistryToken::issue($org, 'a-token', $group, owner: $a);
    RegistryToken::issue($org, 'b-token', $group, owner: $b);

    $this->actingAs($a)->get(route('portal.registries.show', $group->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('tokens', 1)->where('tokens.0.name', 'a-token'));
});
