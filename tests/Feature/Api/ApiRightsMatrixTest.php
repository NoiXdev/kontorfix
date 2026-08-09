<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\RegistryToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A write-capable bearer token for the given user. */
function writeKey(User $user): string
{
    [, $plain] = ApiKey::issue($user, 'w', ApiKeyPermission::Write);

    return $plain;
}

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['name' => 'Org A']);
    $this->orgB = Organization::factory()->create(['name' => 'Org B']);

    $this->groupA = Group::factory()->for($this->orgA)->create(['name' => 'Reg A']);
    $this->groupB = Group::factory()->for($this->orgB)->create(['name' => 'Reg B']);

    $this->pkgA = Package::factory()->create(['name' => 'a/one']);
    $this->groupA->packages()->attach($this->pkgA->id);
    $this->pkgB = Package::factory()->create(['name' => 'b/one']);
    $this->groupB->packages()->attach($this->pkgB->id);

    $this->adminA = User::factory()->for($this->orgA)->create(['role' => UserRole::Admin]);
    $this->memberA = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);
    $this->super = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member, 'is_super_admin' => true]);
});

it('scopes registry reads to the callers own organization', function () {
    $token = writeKey($this->adminA);

    $this->withToken($token)->getJson('/api/v1/groups')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Reg A');

    $this->withToken($token)->getJson('/api/v1/packages')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'a/one');
});

it('forbids reading another organizations registry or package', function () {
    $token = writeKey($this->adminA);

    $this->withToken($token)->getJson("/api/v1/groups/{$this->groupB->id}")->assertForbidden();
    $this->withToken($token)->getJson("/api/v1/packages/{$this->pkgB->id}")->assertForbidden();

    // ...but the own ones are readable.
    $this->withToken($token)->getJson("/api/v1/groups/{$this->groupA->id}")->assertOk();
    $this->withToken($token)->getJson("/api/v1/packages/{$this->pkgA->id}")->assertOk();
});

it('lets an org admin write to their own org but not a foreign one', function () {
    $token = writeKey($this->adminA);

    // Create a registry in the own org (defaults to the caller's home org).
    $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'New A', 'slug' => 'new-a'])
        ->assertCreated();
    expect(Group::where('slug', 'new-a')->first()->organization_id)->toBe($this->orgA->id);

    // Cannot modify or delete a foreign org's registry.
    $this->withToken($token)->putJson("/api/v1/groups/{$this->groupB->id}", ['name' => 'x', 'public' => false])->assertForbidden();
    $this->withToken($token)->deleteJson("/api/v1/groups/{$this->groupB->id}")->assertForbidden();

    // Sub-resources honour the same boundary (group_id in the body satisfies the shared
    // form request; the controller authorises against the {group} route + asserts write).
    $this->withToken($token)->postJson("/api/v1/groups/{$this->groupA->id}/upstreams", [
        'group_id' => $this->groupA->id, 'type' => 'composer',
        'url' => 'https://repo.packagist.org', 'policy' => 'proxy',
    ])->assertCreated();
    $this->withToken($token)->postJson("/api/v1/groups/{$this->groupB->id}/upstreams", [
        'group_id' => $this->groupB->id, 'type' => 'composer',
        'url' => 'https://repo.packagist.org', 'policy' => 'proxy',
    ])->assertForbidden();

    // Domains are the exception and deliberately so: a hostname is a globally unique,
    // instance-wide claim that the application cannot verify, so attaching one is
    // operator-only — refused even on the caller's OWN registry. This case previously
    // asserted assertCreated() here, which is the behaviour the audit charged.
    // See tests/Feature/Admin/DomainOwnershipTest.php for the full boundary.
    $this->withToken($token)->postJson("/api/v1/groups/{$this->groupA->id}/domains", [
        'group_id' => $this->groupA->id, 'hostname' => 'a.example.test',
    ])->assertForbidden();
    expect(Domain::where('hostname', 'a.example.test')->exists())->toBeFalse();
});

it('lets a member read but never write', function () {
    $token = writeKey($this->memberA);

    $this->withToken($token)->getJson('/api/v1/groups')->assertOk()->assertJsonCount(1, 'data');

    // Write endpoints require an admin/maintainer role (the operator gate).
    $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'Nope', 'slug' => 'nope'])->assertForbidden();
    $this->withToken($token)->putJson("/api/v1/groups/{$this->groupA->id}", ['name' => 'x', 'public' => false])->assertForbidden();
    // Registry tokens are org credentials — members cannot list them at all.
    $this->withToken($token)->getJson('/api/v1/registry-tokens')->assertForbidden();
});

it('scopes the registry-token listing to administered organizations', function () {
    RegistryToken::factory()->create(['organization_id' => $this->orgA->id, 'name' => 'tok-a']);
    RegistryToken::factory()->create(['organization_id' => $this->orgB->id, 'name' => 'tok-b']);

    $this->withToken(writeKey($this->adminA))->getJson('/api/v1/registry-tokens')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'tok-a');

    $this->withToken(writeKey($this->super))->getJson('/api/v1/registry-tokens')
        ->assertOk()->assertJsonCount(2, 'data');
});

it('gives a super-admin every organizations registries', function () {
    $this->withToken(writeKey($this->super))->getJson('/api/v1/groups')
        ->assertOk()->assertJsonCount(2, 'data');
});

it('lets a robot inherit the write rights of its per-org role', function () {
    $robot = User::factory()->robot()->for($this->orgA)->create(['role' => UserRole::Maintainer]);
    $token = writeKey($robot);

    // A maintainer robot writes to its own org...
    $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'Robot Reg', 'slug' => 'robot-reg'])->assertCreated();
    // ...but not to a foreign org.
    $this->withToken($token)->deleteJson("/api/v1/groups/{$this->groupB->id}")->assertForbidden();
});

it('restricts a member robot to reads only', function () {
    $robot = User::factory()->robot()->for($this->orgA)->create(['role' => UserRole::Member]);
    $token = writeKey($robot);

    $this->withToken($token)->getJson('/api/v1/groups')->assertOk()->assertJsonCount(1, 'data');
    $this->withToken($token)->postJson('/api/v1/groups', ['name' => 'x', 'slug' => 'x'])->assertForbidden();
});
