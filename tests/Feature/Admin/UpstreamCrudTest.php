<?php

use App\Enums\PackageType;
use App\Enums\UpstreamPolicy;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Upstream;
use App\Models\User;

it('lists upstreams with their group for admins', function () {
    $up = Upstream::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/admin/upstreams')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/upstreams/Index')->has('upstreams', 1));
});

it('creates an upstream with optional strict allowlist', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
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

it('validates the upstream url must be http/https', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->post('/admin/upstreams', ['group_id' => $group->id, 'type' => 'composer', 'url' => 'file:///etc/passwd', 'policy' => 'proxy'])
        ->assertSessionHasErrors('url');
});

it('never exposes the auth token in the index payload', function () {
    Upstream::factory()->create(['auth_token' => 'topsecret']);
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->get('/admin/upstreams')
        ->assertInertia(fn ($page) => $page->has('upstreams.0', fn ($u) => $u
            ->hasAll(['id', 'group', 'type', 'url', 'policy', 'priority', 'enabled', 'has_auth', 'allowed_packages'])
            ->missing('auth_token')
            ->etc()
        ));
});

it('deletes an upstream', function () {
    $up = Upstream::factory()->create();
    $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
        ->delete("/admin/upstreams/{$up->id}")->assertRedirect();
    expect(Upstream::find($up->id))->toBeNull();
});

it('forbids members', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/upstreams')->assertForbidden();
});
