<?php

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->member = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);
    // Minting through the portal sits behind `password.confirm` (see routes/web.php);
    // this file is about the org scoping behind that gate, not the gate itself, which
    // CredentialPasswordConfirmationTest covers.
    $this->withSession(['auth.password_confirmed_at' => time()]);
});

it('creates a token scoped to the members own org and returns the plaintext once', function () {
    $group = Group::factory()->for($this->orgA)->create();

    $this->actingAs($this->member)->from("/c/{$this->orgA->slug}/registries")
        ->post("/c/{$this->orgA->slug}/tokens", ['name' => 'CI', 'group_id' => $group->id, 'ability' => 'read'])
        ->assertRedirect("/c/{$this->orgA->slug}/registries")
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::first();
    expect($token->organization_id)->toBe($this->orgA->id)
        ->and($token->group_id)->toBe($group->id)
        ->and($token->ability)->toBe(TokenAbility::Read);
});

it('rejects assigning a token to a foreign registry', function () {
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->from("/c/{$this->orgA->slug}/registries")
        ->post("/c/{$this->orgA->slug}/tokens", ['name' => 'CI', 'group_id' => $foreign->id])
        ->assertSessionHasErrors('group_id');

    expect(RegistryToken::count())->toBe(0);
});

it('revokes only own personal tokens', function () {
    // Persönliches Token des Members: löschbar.
    $own = RegistryToken::factory()->for($this->orgA)->create(['user_id' => $this->member->id]);
    $foreign = RegistryToken::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->delete("/c/{$this->orgA->slug}/tokens/{$foreign->id}")->assertForbidden();
    expect(RegistryToken::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->member)->from("/c/{$this->orgA->slug}/registries")
        ->delete("/c/{$this->orgA->slug}/tokens/{$own->id}")->assertRedirect("/c/{$this->orgA->slug}/registries");
    expect(RegistryToken::find($own->id))->toBeNull();
});

it('ties a token to the organization whose portal it was minted in', function () {
    $home = Organization::factory()->create(['slug' => 'home']);
    $other = Organization::factory()->create(['slug' => 'other']);
    $user = User::factory()->create(['organization_id' => $home->id, 'role' => 'admin']);
    $user->organizations()->attach($other->id, ['role' => 'admin']);

    $this->actingAs($user)->post('/c/other/tokens', ['name' => 'CI', 'ability' => 'read']);

    // The old code fell back to the user's HOME organization when no group was submitted.
    expect(RegistryToken::latest()->first()->organization_id)->toBe($other->id);
});

it('refuses a group from another organization', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $other = Organization::factory()->create();
    $group = Group::factory()->for($other)->create();
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    $user->organizations()->attach($other->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read', 'group_id' => $group->id])
        ->assertStatus(403);

    // The status alone would not distinguish a refusal from a refusal that still wrote.
    expect(RegistryToken::count())->toBe(0);
});

it('refuses an operator account minting a token in a customer portal', function () {
    $customer = Organization::factory()->create(['slug' => 'acme']);
    Organization::factory()->create(['is_operator' => true]);

    // Viewing and minting are different questions. If looking in to help also issued
    // credentials, support access would be a way to obtain a customer's registry token.
    $this->actingAs(superAdmin())
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read'])
        ->assertStatus(403);

    // Not merely "no token for the customer": no token at all. The old code would have
    // minted one against the operator's OWN organization and answered 302.
    expect(RegistryToken::count())->toBe(0);
    expect(RegistryToken::where('organization_id', $customer->id)->count())->toBe(0);
});

it('still lets a member of the organization mint a token', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);

    $this->actingAs($user)
        ->post('/c/acme/tokens', ['name' => 'CI', 'ability' => 'read'])
        ->assertRedirect();
});
