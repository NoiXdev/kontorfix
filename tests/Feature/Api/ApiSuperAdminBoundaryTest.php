<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Every instance-wide management endpoint on the v1 API (GET is enough to hit the gate). */
function managementEndpoints(): array
{
    return [
        '/api/v1/packages',
        '/api/v1/groups',
        '/api/v1/registry-tokens',
        '/api/v1/webhooks',
        '/api/v1/status',
        '/api/v1/organizations',
        '/api/v1/users',
    ];
}

it('denies a customer-org admin the entire management API', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($custAdmin, 'w', ApiKeyPermission::Write);

    foreach (managementEndpoints() as $url) {
        $this->withToken($plain)->getJson($url)->assertForbidden();
    }
});

it('denies an operator-org maintainer the management API (tightened to super-admin)', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $maint = User::factory()->for($op)->create(['role' => UserRole::Maintainer]);
    [, $plain] = ApiKey::issue($maint, 'w', ApiKeyPermission::Write);

    foreach (managementEndpoints() as $url) {
        $this->withToken($plain)->getJson($url)->assertForbidden();
    }
});

it('still lets any authenticated key holder use the self-service endpoints', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    [, $plain] = ApiKey::issue($custAdmin, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $custAdmin->id);
    $this->withToken($plain)->getJson('/api/v1/me/api-keys')->assertOk();
    $this->withToken($plain)->postJson('/api/v1/me/api-keys', ['name' => 'ci', 'permission' => 'read'])->assertCreated();
});

it('lets a flag-based super-admin (in a customer org) use the management API', function () {
    $superInCustomerOrg = User::factory()
        ->for(Organization::factory()->create(['is_operator' => false]))
        ->create(['role' => UserRole::Member, 'is_super_admin' => true]);
    [, $plain] = ApiKey::issue($superInCustomerOrg, 'w', ApiKeyPermission::Write);

    foreach (managementEndpoints() as $url) {
        $this->withToken($plain)->getJson($url)->assertOk();
    }
});
