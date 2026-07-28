<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects requests without a valid bearer key', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
    $this->withToken('kfxapi_invalid')->getJson('/api/v1/me')->assertUnauthorized();
});

it('authenticates as the key owner and returns the profile', function () {
    $user = User::factory()->create(['name' => 'Ada']);
    [, $plain] = ApiKey::issue($user, 'ci', ApiKeyPermission::Read);

    $this->withToken($plain)->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.name', 'Ada')
        ->assertJsonPath('data.account_type', 'human');
});

it('updates last_used_at on use', function () {
    $user = User::factory()->create();
    [$key, $plain] = ApiKey::issue($user, 'ci', ApiKeyPermission::Read);
    expect($key->last_used_at)->toBeNull();

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk();

    expect($key->fresh()->last_used_at)->not->toBeNull();
});
