<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Endpoints with no per-organization dimension — super-admin only. */
function instanceOnlyEndpoints(): array
{
    return ['/api/v1/webhooks', '/api/v1/status', '/api/v1/organizations', '/api/v1/users'];
}

it('denies a customer-org admin the instance-wide endpoints', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($custAdmin, 'w', ApiKeyPermission::Write);

    foreach (instanceOnlyEndpoints() as $url) {
        $this->withToken($plain)->getJson($url)->assertForbidden();
    }
});

it('denies an operator-org maintainer the instance-wide endpoints', function () {
    $maint = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Maintainer]);
    [, $plain] = ApiKey::issue($maint, 'w', ApiKeyPermission::Write);

    foreach (instanceOnlyEndpoints() as $url) {
        $this->withToken($plain)->getJson($url)->assertForbidden();
    }
});

it('still lets any authenticated key holder use the self-service endpoints', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($custAdmin, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $custAdmin->id);
    $this->withToken($plain)->getJson('/api/v1/me/api-keys')->assertOk();
    $this->withToken($plain)->postJson('/api/v1/me/api-keys', [
        'name' => 'ci', 'permission' => 'read', 'expires_at' => now()->addDays(30)->toIso8601String(),
    ])->assertCreated();
});

it('lets a flag-based super-admin (in a customer org) use the instance-wide endpoints', function () {
    $super = User::factory()
        ->for(Organization::factory()->create(['is_operator' => false]))
        ->create(['role' => UserRole::Member, 'is_super_admin' => true]);
    [, $plain] = ApiKey::issue($super, 'w', ApiKeyPermission::Write);

    foreach (instanceOnlyEndpoints() as $url) {
        $this->withToken($plain)->getJson($url)->assertOk();
    }
});
