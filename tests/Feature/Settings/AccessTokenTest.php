<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// The whole `settings/tokens` area sits behind `password.confirm`; these tests cover the
// token mechanics, so they start from a confirmed-password session. The gate itself is
// covered in CredentialPasswordConfirmationTest.
beforeEach(fn () => $this->withSession(['auth.password_confirmed_at' => time()]));

it('lists only the current users own personal tokens', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);
    $other = User::factory()->create(['organization_id' => $org->id]);

    RegistryToken::issue($org, 'meins', null, owner: $me);
    RegistryToken::issue($org, 'fremd', null, owner: $other);

    $this->actingAs($me)->get('/settings/tokens')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/AccessTokens')
            ->has('tokens', 1)
            ->where('tokens.0.name', 'meins'));
});

it('creates a personal global token owned by the user', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($me)->post('/settings/tokens', ['name' => 'global', 'ability' => 'read'])
        ->assertRedirect()
        ->assertSessionHas('plainTextToken');

    $token = RegistryToken::firstWhere('name', 'global');
    expect($token->user_id)->toBe($me->id);
    expect($token->group_id)->toBeNull();
    expect($token->organization_id)->toBe($org->id);
});

it('rejects a registry belonging to another organization', function () {
    $me = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $foreignGroup = Group::factory()->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($me)->post('/settings/tokens', ['name' => 'x', 'group_id' => $foreignGroup->id])
        ->assertSessionHasErrors('group_id');
});

it('forbids deleting a token owned by someone else', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);
    $other = User::factory()->create(['organization_id' => $org->id]);
    [$token] = RegistryToken::issue($org, 'fremd', null, owner: $other);

    $this->actingAs($me)->delete("/settings/tokens/{$token->id}")->assertForbidden();
    expect(RegistryToken::find($token->id))->not->toBeNull();
});

it('deletes an own token', function () {
    $org = Organization::factory()->create();
    $me = User::factory()->create(['organization_id' => $org->id]);
    [$token] = RegistryToken::issue($org, 'meins', null, owner: $me);

    $this->actingAs($me)->delete("/settings/tokens/{$token->id}")->assertRedirect();
    expect(RegistryToken::find($token->id))->toBeNull();
});
