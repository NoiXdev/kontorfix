<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only own keys and creates one with a flashed plaintext', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    ApiKey::issue($me, 'mine', ApiKeyPermission::Read);
    ApiKey::issue($other, 'theirs', ApiKeyPermission::Read);

    $this->actingAs($me)->get('/settings/api-keys')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('settings/ApiKeys')->has('apiKeys', 1));

    $this->actingAs($me)->post('/settings/api-keys', ['name' => 'deploy', 'permission' => 'write'])
        ->assertRedirect()->assertSessionHas('plainApiKey');
});

it('forbids deleting a foreign key', function () {
    $me = User::factory()->create();
    [$foreign] = ApiKey::issue(User::factory()->create(), 'x', ApiKeyPermission::Read);
    $this->actingAs($me)->delete("/settings/api-keys/{$foreign->id}")->assertForbidden();
});
