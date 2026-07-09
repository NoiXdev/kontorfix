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
});

it('creates a token scoped to the members own org and returns the plaintext once', function () {
    $group = Group::factory()->for($this->orgA)->create();

    $this->actingAs($this->member)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $group->id, 'ability' => 'read'])
        ->assertRedirect('/portal')
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::first();
    expect($token->organization_id)->toBe($this->orgA->id)
        ->and($token->group_id)->toBe($group->id)
        ->and($token->ability)->toBe(TokenAbility::Read);
});

it('rejects assigning a token to a foreign registry', function () {
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->from('/portal')
        ->post('/portal/tokens', ['name' => 'CI', 'group_id' => $foreign->id])
        ->assertSessionHasErrors('group_id');

    expect(RegistryToken::count())->toBe(0);
});

it('revokes only own personal tokens', function () {
    // Persönliches Token des Members: löschbar.
    $own = RegistryToken::factory()->for($this->orgA)->create(['user_id' => $this->member->id]);
    $foreign = RegistryToken::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->delete("/portal/tokens/{$foreign->id}")->assertForbidden();
    expect(RegistryToken::find($foreign->id))->not->toBeNull();

    $this->actingAs($this->member)->from('/portal')->delete("/portal/tokens/{$own->id}")->assertRedirect('/portal');
    expect(RegistryToken::find($own->id))->toBeNull();
});
