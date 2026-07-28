<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
    $this->group = Group::factory()->create(['organization_id' => $this->org->id]);
});

it('adds and removes a domain', function () {
    $res = $this->withToken($this->plain)->postJson("/api/v1/groups/{$this->group->id}/domains", [
        'group_id' => $this->group->id,
        'hostname' => 'packages.acme.test',
    ])->assertCreated()->assertJsonPath('data.hostname', 'packages.acme.test');

    $id = $res->json('data.id');
    $this->withToken($this->plain)->deleteJson("/api/v1/groups/{$this->group->id}/domains/{$id}")->assertNoContent();
});

it('adds and removes an upstream', function () {
    $res = $this->withToken($this->plain)->postJson("/api/v1/groups/{$this->group->id}/upstreams", [
        'group_id' => $this->group->id,
        'type' => 'composer',
        'url' => 'https://packagist.org',
        'policy' => 'proxy',
    ])->assertCreated()->assertJsonPath('data.url', 'https://packagist.org');

    $id = $res->json('data.id');
    $this->withToken($this->plain)->deleteJson("/api/v1/groups/{$this->group->id}/upstreams/{$id}")->assertNoContent();
});

it('sets the package assignment', function () {
    $a = Package::factory()->create();
    $b = Package::factory()->create();

    $this->withToken($this->plain)->putJson("/api/v1/groups/{$this->group->id}/packages", [
        'package_ids' => [$a->id, $b->id],
    ])->assertOk()->assertJsonCount(2, 'data');

    expect($this->group->fresh()->packages()->count())->toBe(2);
});
