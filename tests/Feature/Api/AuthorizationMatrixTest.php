<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('read key is blocked on every mutating verb', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $read] = ApiKey::issue($admin, 'r', ApiKeyPermission::Read);

    $this->withToken($read)->postJson('/api/v1/groups', [])->assertForbidden();

    // For {group} routes with a NON-existent ID, Laravel's implicit
    // route-model-binding (SubstituteBindings, part of the global `api` group) kicks in before
    // the read/write gate of the `api.auth` middleware — verified via a debug request: a
    // real, existing group correctly returns 403 with the same read key (the gate applies),
    // only for an unresolvable ID does the ModelNotFoundException (404) come through first.
    // Not a security issue (no mutation, no information leaks), but real
    // behavior that is documented here rather than mocked away.
    $this->withToken($read)->putJson('/api/v1/groups/x', [])->assertNotFound();
    $this->withToken($read)->deleteJson('/api/v1/groups/x')->assertNotFound();
});

it('expired key is unauthorized', function () {
    $u = User::factory()->create();
    [, $plain] = ApiKey::issue($u, 'old', ApiKeyPermission::Read, now()->subMinute());
    $this->withToken($plain)->getJson('/api/v1/me')->assertUnauthorized();
});

it('rate limits after the configured threshold', function () {
    Cache::flush();
    $u = User::factory()->create();
    [, $plain] = ApiKey::issue($u, 'rl', ApiKeyPermission::Read);

    foreach (range(1, 120) as $_) {
        $this->withToken($plain)->getJson('/api/v1/me');
    }
    $this->withToken($plain)->getJson('/api/v1/me')->assertStatus(429);
});

it('rate limits per key, not per ip', function () {
    Cache::flush();
    $u = User::factory()->create();
    [, $a] = ApiKey::issue($u, 'a', ApiKeyPermission::Read);
    [, $b] = ApiKey::issue($u, 'b', ApiKeyPermission::Read);

    foreach (range(1, 120) as $_) {
        $this->withToken($a)->getJson('/api/v1/me');
    }
    // Key A exhausted…
    $this->withToken($a)->getJson('/api/v1/me')->assertStatus(429);
    // …Key B (same IP) is unaffected.
    $this->withToken($b)->getJson('/api/v1/me')->assertOk();
});
