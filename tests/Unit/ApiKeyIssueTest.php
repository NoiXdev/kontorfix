<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a hashed api key and returns the plaintext once', function () {
    $user = User::factory()->create();

    [$key, $plain] = ApiKey::issue($user, 'ci', ApiKeyPermission::Write);

    expect($plain)->toStartWith('kfxapi_');
    expect($key->user_id)->toBe($user->id);
    expect($key->permission)->toBe(ApiKeyPermission::Write);
    expect($key->key_hash)->toBe(hash('sha256', $plain));
    expect($key->getAttributes())->not->toHaveKey('plain');
});

it('finds a key by plaintext and ignores expired ones', function () {
    $user = User::factory()->create();
    [, $plain] = ApiKey::issue($user, 'valid', ApiKeyPermission::Read);
    [$expired, $expiredPlain] = ApiKey::issue($user, 'old', ApiKeyPermission::Read, now()->subDay());

    expect(ApiKey::findByPlainText($plain)?->name)->toBe('valid');
    expect(ApiKey::findByPlainText($expiredPlain))->toBeNull();
    expect(ApiKey::findByPlainText('kfxapi_nonexistent'))->toBeNull();
});
