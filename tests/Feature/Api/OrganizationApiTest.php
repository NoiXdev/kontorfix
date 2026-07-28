<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets an operator admin create and delete customer orgs', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $op->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    $res = $this->withToken($plain)->postJson('/api/v1/organizations', ['name' => 'Kunde X', 'slug' => 'kunde-x'])
        ->assertCreated()->assertJsonPath('data.is_operator', false);

    $id = $res->json('data.id');
    $this->withToken($plain)->deleteJson("/api/v1/organizations/{$id}")->assertNoContent();
});

it('denies maintainers', function () {
    $op = Organization::factory()->create(['is_operator' => true]);
    $maint = User::factory()->create(['organization_id' => $op->id, 'role' => 'maintainer']);
    [, $plain] = ApiKey::issue($maint, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/organizations')->assertForbidden();
});
