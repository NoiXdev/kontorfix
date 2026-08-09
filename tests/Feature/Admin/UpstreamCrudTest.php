<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Upstream;
use App\Models\User;

it('lists upstreams with their group for admins', function () {
    $up = Upstream::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/upstreams')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/upstreams/Index')->has('upstreams', 1));
});

it('creates an upstream with optional strict allowlist', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/upstreams', [
            'group_id' => $group->id,
            'type' => 'composer',
            'url' => 'https://repo.packagist.org',
            'policy' => 'strict',
            'auth_token' => 'secret',
            'priority' => 0,
            'allowed_packages' => ['symfony/console', 'psr/log'],
        ])->assertRedirect();

    $up = Upstream::where('group_id', $group->id)->firstOrFail();
    expect($up->policy)->toBe(UpstreamPolicy::Strict)
        ->and($up->type)->toBe(PackageType::Composer)
        ->and($up->allowedPackages()->pluck('name')->all())->toContain('symfony/console', 'psr/log');
});

it('creates a proxy upstream with a priority and an encrypted auth token', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/upstreams', [
            'group_id' => $group->id,
            'type' => 'python',
            'url' => 'https://pypi.org/simple',
            'policy' => 'proxy',
            'auth_token' => 'mirror-secret',
            'priority' => 5,
        ])->assertRedirect();

    $up = Upstream::where('group_id', $group->id)->firstOrFail();
    expect($up->type)->toBe(PackageType::Python)
        ->and($up->policy)->toBe(UpstreamPolicy::Proxy)
        ->and($up->priority)->toBe(5)
        ->and($up->auth_token)->toBe('mirror-secret');
});

it('validates the upstream url must be http/https', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/upstreams', ['group_id' => $group->id, 'type' => 'composer', 'url' => 'file:///etc/passwd', 'policy' => 'proxy'])
        ->assertSessionHasErrors('url');
});

it('never exposes the auth token in the index payload', function () {
    Upstream::factory()->create(['auth_token' => 'topsecret']);
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/upstreams')
        ->assertInertia(fn ($page) => $page->has('upstreams.0', fn ($u) => $u
            ->hasAll(['id', 'group', 'type', 'url', 'policy', 'priority', 'enabled', 'has_auth', 'allowed_packages'])
            ->missing('auth_token')
            ->etc()
        ));
});

it('updates an upstream and keeps the token when the field is left blank', function () {
    $up = Upstream::factory()->create([
        'type' => 'composer', 'policy' => 'proxy', 'priority' => 0,
        'url' => 'https://registry.npmjs.test', 'auth_token' => 'keep-me',
    ]);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    // Same host: keeping the stored token is safe (a host change would not be — see
    // UpstreamTokenRetargetTest).
    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => 'npm',
        'url' => 'https://registry.npmjs.test',
        'policy' => 'proxy',
        'priority' => 7,
        'auth_token' => '',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $up->refresh();
    expect($up->type)->toBe(PackageType::Npm)
        ->and($up->url)->toBe('https://registry.npmjs.test')
        ->and($up->priority)->toBe(7)
        ->and($up->auth_token)->toBe('keep-me');
});

it('replaces the token when provided and removes it on request', function () {
    $up = Upstream::factory()->create(['auth_token' => 'old', 'policy' => 'proxy']);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => $up->type->value, 'url' => $up->url, 'policy' => 'proxy', 'auth_token' => 'new-secret',
    ])->assertRedirect();
    expect($up->fresh()->auth_token)->toBe('new-secret');

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => $up->type->value, 'url' => $up->url, 'policy' => 'proxy', 'remove_auth_token' => true,
    ])->assertRedirect();
    expect($up->fresh()->auth_token)->toBeNull();
});

it('rewrites the strict allowlist on update', function () {
    $up = Upstream::factory()->create(['policy' => 'strict']);
    $up->allowedPackages()->create(['name' => 'old/pkg']);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->put("/admin/upstreams/{$up->id}", [
        'type' => $up->type->value, 'url' => $up->url, 'policy' => 'strict',
        'allowed_packages' => ['new/one', 'new/two'],
    ])->assertRedirect();

    expect($up->fresh()->allowedPackages()->pluck('name')->all())
        ->toEqualCanonicalizing(['new/one', 'new/two']);
});

it('forbids editing an upstream in a foreign organization', function () {
    $up = Upstream::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->put("/admin/upstreams/{$up->id}", [
            'type' => 'composer', 'url' => 'https://repo.packagist.org', 'policy' => 'proxy',
        ])->assertForbidden();
});

it('deletes an upstream', function () {
    $up = Upstream::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->delete("/admin/upstreams/{$up->id}")->assertRedirect();
    expect(Upstream::find($up->id))->toBeNull();
});

it('forbids members', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/upstreams')->assertForbidden();
});
