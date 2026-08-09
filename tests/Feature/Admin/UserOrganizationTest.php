<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

function opAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('edits a user name and email, not just the role', function () {
    $admin = opAdmin();
    $target = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create([
        'name' => 'Old Name', 'email' => 'old@example.com', 'role' => UserRole::Member,
    ]);

    // Moving somebody's reset channel re-proves the password (ConfirmPasswordOnEmailChange
    // on the route). Note the sibling case below, which keeps the address as it is: that
    // one is deliberately *not* stamped, because the gate must only engage on a change.
    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->put("/admin/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
            'role' => 'member',
        ])->assertRedirect()->assertSessionHasNoErrors();

    $target->refresh();
    expect($target->name)->toBe('New Name');
    expect($target->email)->toBe('new@example.com');
});

it('rejects an email already used by another user', function () {
    $admin = opAdmin();
    User::factory()->create(['email' => 'taken@example.com']);
    $target = User::factory()->create(['email' => 'target@example.com']);

    $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
        ->put("/admin/users/{$target->id}", [
            'name' => 'X', 'email' => 'taken@example.com', 'role' => 'member',
        ])->assertSessionHasErrors('email');
});

it('keeps a users own email valid on edit', function () {
    $admin = opAdmin();
    $target = User::factory()->create(['email' => 'keep@example.com']);

    $this->actingAs($admin)->put("/admin/users/{$target->id}", [
        'name' => 'X', 'email' => 'keep@example.com', 'role' => 'member',
    ])->assertRedirect()->assertSessionHasNoErrors();
});

it('grants a user additional organization access from the user view', function () {
    $admin = opAdmin();
    $home = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = User::factory()->for($home)->create();

    $this->actingAs($admin)->post("/admin/users/{$user->id}/organizations", [
        'organization_id' => $other->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($user->fresh()->accessibleOrganizationIds())
        ->toContain($home->id)
        ->toContain($other->id);
});

it('refuses to attach the home organization as an additional membership', function () {
    $admin = opAdmin();
    $home = Organization::factory()->create();
    $user = User::factory()->for($home)->create();

    $this->actingAs($admin)->post("/admin/users/{$user->id}/organizations", [
        'organization_id' => $home->id,
    ])->assertSessionHasErrors('organization_id');
});

it('revokes additional organization access', function () {
    $admin = opAdmin();
    $other = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($other->id);

    $this->actingAs($admin)->delete("/admin/users/{$user->id}/organizations/{$other->id}")
        ->assertRedirect();

    expect($user->fresh()->accessibleOrganizationIds())->not->toContain($other->id);
});

it('adds and removes members from the organization view', function () {
    $admin = opAdmin();
    $org = Organization::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($admin)->post("/admin/organizations/{$org->id}/members", ['user_id' => $user->id])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect($org->members()->whereKey($user->id)->exists())->toBeTrue();

    $this->actingAs($admin)->delete("/admin/organizations/{$org->id}/members/{$user->id}")
        ->assertRedirect();
    expect($org->members()->whereKey($user->id)->exists())->toBeFalse();
});

it('attaches a member with a per-organization role from the organization view', function () {
    $admin = opAdmin();
    $org = Organization::factory()->create();
    $user = User::factory()->create(['role' => UserRole::Member]);

    $this->actingAs($admin)->post("/admin/organizations/{$org->id}/members", [
        'user_id' => $user->id, 'role' => 'admin',
    ])->assertRedirect()->assertSessionHasNoErrors();

    // The membership carries the chosen role, granting admin reach to that org only.
    $fresh = $user->fresh();
    expect($fresh->administers($org->id))->toBeTrue()
        ->and($fresh->roleIn($org->id))->toBe(UserRole::Admin);
});

it('shows registries of additional organizations in the portal', function () {
    $home = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = User::factory()->for($home)->create(['role' => UserRole::Member]);
    $user->organizations()->attach($other->id);

    $homeGroup = Group::factory()->for($home)->create(['name' => 'Home Reg']);
    $otherGroup = Group::factory()->for($other)->create(['name' => 'Other Reg']);

    $this->actingAs($user)->get('/portal')
        ->assertInertia(fn ($page) => $page
            ->has('registries', 2)
            ->where('registries.0.name', 'Home Reg')
            ->where('registries.1.name', 'Other Reg'));
});

it('hides portal-disabled groups from the portal listing and blocks direct access', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $collection = Group::factory()->for($org)->create(['portal_enabled' => false]);

    $this->actingAs($user)->get('/portal')
        ->assertInertia(fn ($page) => $page->has('registries', 0));

    $this->actingAs($user)->get("/portal/registries/{$collection->id}")->assertForbidden();
});

it('lets a member create a token for a registry of an additional organization', function () {
    // Portal minting is behind `password.confirm`; the additional-org reach is the subject.
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $home = Organization::factory()->create();
    $other = Organization::factory()->create();
    $user = User::factory()->for($home)->create(['role' => UserRole::Member]);
    $user->organizations()->attach($other->id);
    $group = Group::factory()->for($other)->create();

    $this->actingAs($user)->post('/portal/tokens', [
        'name' => 'ci', 'group_id' => $group->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($group->tokens()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('still refuses a token for a foreign organizations registry', function () {
    $this->withSession(['auth.password_confirmed_at' => time()]);

    $user = User::factory()->for(Organization::factory()->create())->create(['role' => UserRole::Member]);
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($user)->post('/portal/tokens', [
        'name' => 'ci', 'group_id' => $foreign->id,
    ])->assertSessionHasErrors('group_id');
});
