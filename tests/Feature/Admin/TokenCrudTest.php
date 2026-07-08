<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

it('lists tokens for admins', function () {
    $org = Organization::factory()->create();
    RegistryToken::issue($org, 'ci', null);
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->get('/admin/tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/tokens/Index')->has('tokens', 1));
});

it('creates a token and flashes the plaintext exactly once', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();

    $res = $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/tokens', ['name' => 'kadenz-ci', 'organization_id' => $org->id, 'group_id' => $group->id]);

    $res->assertRedirect()->assertSessionHas('plainTextToken');
    expect(session('plainTextToken'))->toStartWith('kfx_')
        ->and(RegistryToken::where('name', 'kadenz-ci')->exists())->toBeTrue();
});

it('creates an org-wide token when no group is given', function () {
    $org = Organization::factory()->create();
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/tokens', ['name' => 'org-wide', 'organization_id' => $org->id])
        ->assertRedirect();
    expect(RegistryToken::where('name', 'org-wide')->first()->group_id)->toBeNull();
});

it('validates that the group belongs to the chosen organization', function () {
    $org = Organization::factory()->create();
    $otherGroup = Group::factory()->for(Organization::factory())->create();
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->post('/admin/tokens', ['name' => 'x', 'organization_id' => $org->id, 'group_id' => $otherGroup->id])
        ->assertSessionHasErrors('group_id');
});

it('revokes tokens by deletion', function () {
    $org = Organization::factory()->create();
    [$token] = RegistryToken::issue($org, 'x', null);
    $this->actingAs(User::factory()->for($org)->create(['role' => UserRole::Admin]))
        ->delete("/admin/tokens/{$token->id}")->assertRedirect();
    expect(RegistryToken::find($token->id))->toBeNull();
});

it('forbids members from managing tokens', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/tokens')->assertForbidden();
});
