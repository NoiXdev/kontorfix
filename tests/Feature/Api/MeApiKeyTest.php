<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lists only the owners keys', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    ApiKey::issue($me, 'mine', ApiKeyPermission::Read);
    ApiKey::issue($other, 'theirs', ApiKeyPermission::Read);
    [, $plain] = ApiKey::issue($me, 'auth', ApiKeyPermission::Read);

    $this->withToken($plain)->getJson('/api/v1/me/api-keys')
        ->assertOk()
        ->assertJsonCount(2, 'data'); // mine + auth, not theirs
});

it('read keys cannot create, write keys can', function () {
    $me = User::factory()->create();
    [, $readPlain] = ApiKey::issue($me, 'r', ApiKeyPermission::Read);
    [, $writePlain] = ApiKey::issue($me, 'w', ApiKeyPermission::Write);

    $this->withToken($readPlain)->postJson('/api/v1/me/api-keys', ['name' => 'x', 'permission' => 'read'])
        ->assertForbidden();

    $this->withToken($writePlain)->postJson('/api/v1/me/api-keys', ['name' => 'deploy', 'permission' => 'write'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'deploy')
        ->assertJsonPath('data.plain_text', fn ($v) => str_starts_with($v, 'kfxapi_'));
});

it('forbids deleting a foreign key', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    [$foreign] = ApiKey::issue($other, 'theirs', ApiKeyPermission::Read);
    [, $writePlain] = ApiKey::issue($me, 'w', ApiKeyPermission::Write);

    $this->withToken($writePlain)->deleteJson("/api/v1/me/api-keys/{$foreign->id}")->assertForbidden();
    expect(ApiKey::find($foreign->id))->not->toBeNull();
});
