<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use App\Models\User;

/**
 * An upstream's `auth_token` is a mirror credential (Private Packagist, npm Enterprise,
 * …) that is encrypted, hidden and never readable through the application. Rewriting the
 * upstream URL while the stored token is retained would send that credential to the new
 * host on the very next metadata request.
 */
it('refuses to move an upstream to another host while keeping the stored token', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => 'mirror-secret',
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'composer', 'url' => 'https://evil.test', 'policy' => 'proxy', 'auth_token' => '',
    ])->assertSessionHasErrors('auth_token');

    $up->refresh();
    expect($up->url)->toBe('https://repo.packagist.test')
        ->and($up->auth_token)->toBe('mirror-secret');
});

it('allows moving an upstream to another host when a token for it is supplied', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => 'mirror-secret',
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'composer', 'url' => 'https://mirror.example.test', 'policy' => 'proxy', 'auth_token' => 'new-secret',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $up->refresh();
    expect($up->url)->toBe('https://mirror.example.test')
        ->and($up->auth_token)->toBe('new-secret');
});

it('allows moving an upstream to another host when the stored token is dropped', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => 'mirror-secret',
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'composer', 'url' => 'https://public.example.test', 'policy' => 'proxy', 'remove_auth_token' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $up->refresh();
    expect($up->url)->toBe('https://public.example.test')
        ->and($up->auth_token)->toBeNull();
});

it('refuses a port change on the same host while keeping the stored token', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => 'mirror-secret',
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    // UpstreamClient treats a different port as a different host for auth forwarding,
    // so the retarget guard must too.
    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'composer', 'url' => 'https://repo.packagist.test:8443', 'policy' => 'proxy', 'auth_token' => '',
    ])->assertSessionHasErrors('auth_token');

    expect($up->fresh()->url)->toBe('https://repo.packagist.test');
});

it('keeps the token for a path or scheme change on the same host', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'http://repo.packagist.test', 'auth_token' => 'mirror-secret',
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    // Same host — not a retarget, and upgrading to https must stay frictionless.
    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'composer', 'url' => 'https://repo.packagist.test/mirror', 'policy' => 'proxy', 'auth_token' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $up->refresh();
    expect($up->url)->toBe('https://repo.packagist.test/mirror')
        ->and($up->auth_token)->toBe('mirror-secret');
});

// The guard itself reads `auth_token`, so it must never run for someone who is not
// allowed to know anything about that upstream: a 422 naming `auth_token` versus a plain
// 403 tells an outsider whether a foreign tenant's upstream holds a mirror credential.
it('answers a foreign tenant identically whether or not the upstream holds a token', function () {
    $outsider = User::factory()
        ->for(Organization::factory()->create(['is_operator' => false]))
        ->create(['role' => UserRole::Admin]);

    $withToken = Upstream::factory()->for(Group::factory()->for(Organization::factory()))->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => 'mirror-secret',
    ]);
    $withoutToken = Upstream::factory()->for(Group::factory()->for(Organization::factory()))->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => null,
    ]);

    $payload = ['type' => 'composer', 'url' => 'https://evil.test', 'policy' => 'proxy', 'auth_token' => ''];

    $this->actingAs($outsider)->put("/admin/upstreams/{$withToken->id}", $payload)->assertForbidden();
    $this->actingAs($outsider)->put("/admin/upstreams/{$withoutToken->id}", $payload)->assertForbidden();

    expect($withToken->fresh()->url)->toBe('https://repo.packagist.test');
});

it('allows a host change on an upstream that has no stored token', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'url' => 'https://repo.packagist.test', 'auth_token' => null,
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'composer', 'url' => 'https://other.example.test', 'policy' => 'proxy',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($up->fresh()->url)->toBe('https://other.example.test');
});
