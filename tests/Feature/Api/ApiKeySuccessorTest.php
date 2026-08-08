<?php

// `POST /api/v1/me/api-keys` cannot carry `password.confirm` — the gate reads a session key
// and /api/v1 is stateless. A key therefore mints its own successors, which is how a leaked
// key survives its own revocation. The route stays (it is a robot's only way to rotate its
// own credential), but a successor may be neither wider nor longer-lived than its parent.

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\User;

it('refuses a successor that outlives the key that minted it', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'parent', ApiKeyPermission::Write, now()->addDays(7));

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'immortal',
        'permission' => 'write',
        'expires_at' => now()->addDays(3650)->toIso8601String(),
    ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    expect($me->apiKeys()->count())->toBe(1);
});

it('refuses a never-expiring successor of an expiring key', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'parent', ApiKeyPermission::Write, now()->addDays(7));

    // The default shape of the attack: omit expires_at entirely and the successor used to
    // outlive every rotation of the credential it was minted from.
    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'immortal',
        'permission' => 'write',
    ])->assertStatus(422)->assertJsonValidationErrors('expires_at');

    expect($me->apiKeys()->count())->toBe(1);
});

it('allows a successor that dies with or before its parent', function () {
    $me = User::factory()->create();
    [, $plain] = ApiKey::issue($me, 'parent', ApiKeyPermission::Write, now()->addDays(7));

    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'rotation',
        'permission' => 'write',
        'expires_at' => now()->addDays(6)->toIso8601String(),
    ])->assertCreated();

    expect($me->apiKeys()->count())->toBe(2);
});

it('honours the instance-wide ceiling on the web form too', function () {
    config(['kontorfix.api_key_max_ttl_days' => 30]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/api-keys', [
            'name' => 'forever',
            'permission' => 'read',
        ])->assertSessionHasErrors('expires_at');

    expect($user->apiKeys()->count())->toBe(0);
});

it('leaves the perpetual web-minted key alone when no ceiling is configured', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post('/settings/api-keys', [
            'name' => 'ci',
            'permission' => 'read',
        ])->assertSessionHasNoErrors();

    expect($user->apiKeys()->sole()->expires_at)->toBeNull();
});
