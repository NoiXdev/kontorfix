<?php

use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

// Minting a registry token or an API key hands out a long-lived bearer credential that
// outlives the session it was created from. Like passkey registration, it must re-prove
// the password, so a stolen session cannot be upgraded into durable standalone access.

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->user = User::factory()->create(['organization_id' => $this->organization->id]);
});

it('requires password confirmation to list registry tokens', function () {
    $this->actingAs($this->user)->get('/settings/tokens')
        ->assertRedirect(route('password.confirm'));
});

it('requires password confirmation to mint a registry token', function () {
    $this->actingAs($this->user)->post('/settings/tokens', ['name' => 'stolen'])
        ->assertRedirect(route('password.confirm'));

    expect(RegistryToken::where('user_id', $this->user->id)->count())->toBe(0);
});

it('requires password confirmation to list api keys', function () {
    $this->actingAs($this->user)->get('/settings/api-keys')
        ->assertRedirect(route('password.confirm'));
});

it('requires password confirmation to mint an api key', function () {
    $this->actingAs($this->user)->post('/settings/api-keys', ['name' => 'stolen', 'permission' => 'read'])
        ->assertRedirect(route('password.confirm'));

    expect(ApiKey::where('user_id', $this->user->id)->count())->toBe(0);
});

it('lets a user with a freshly confirmed password mint a registry token', function () {
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/tokens', ['name' => 'ci'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RegistryToken::where('user_id', $this->user->id)->count())->toBe(1);
});
