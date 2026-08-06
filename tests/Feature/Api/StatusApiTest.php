<?php

use App\Enums\ApiKeyPermission;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function tokenFor(User $user): string
{
    [, $plain] = ApiKey::issue($user, 'r', ApiKeyPermission::Read);

    return $plain;
}

beforeEach(function () {
    $this->orgA = Organization::factory()->create();
    $this->orgB = Organization::factory()->create();
    $this->groupA = Group::factory()->for($this->orgA)->create();
    $this->groupB = Group::factory()->for($this->orgB)->create();

    // One failed package in each org.
    $this->failedA = Package::factory()->create(['name' => 'a/broken', 'sync_status' => SyncStatus::Failed, 'sync_error' => 'boom A']);
    $this->groupA->packages()->attach($this->failedA->id);
    $this->okA = Package::factory()->create(['name' => 'a/ok', 'sync_status' => SyncStatus::Synced]);
    $this->groupA->packages()->attach($this->okA->id);
    $this->failedB = Package::factory()->create(['name' => 'b/broken', 'sync_status' => SyncStatus::Failed, 'sync_error' => 'boom B']);
    $this->groupB->packages()->attach($this->failedB->id);

    $this->adminA = User::factory()->for($this->orgA)->create(['role' => UserRole::Admin]);
    $this->super = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('exposes the system status only to super-admins', function () {
    $this->withToken(tokenFor($this->adminA))->getJson('/api/v1/status')->assertForbidden();

    $this->withToken(tokenFor($this->super))->getJson('/api/v1/status')
        ->assertOk()
        ->assertJsonStructure(['data' => ['healthy', 'checks', 'failed_jobs', 'packages', 'sync' => ['synced', 'syncing', 'pending', 'failed']]]);
});

it('reports package health scoped to the callers own organizations', function () {
    $res = $this->withToken(tokenFor($this->adminA))->getJson('/api/v1/status/packages')->assertOk();

    // Org A only: two packages, one failed.
    $res->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.sync.failed', 1)
        ->assertJsonPath('data.sync.synced', 1)
        ->assertJsonCount(1, 'data.failed')
        ->assertJsonPath('data.failed.0.name', 'a/broken')
        ->assertJsonPath('data.failed.0.error', 'boom A');
});

it('shows a super-admin every failed package', function () {
    $res = $this->withToken(tokenFor($this->super))->getJson('/api/v1/status/packages')->assertOk();

    $res->assertJsonPath('data.sync.failed', 2)->assertJsonCount(2, 'data.failed');
});

it('lets a member see their own package health but not another orgs', function () {
    $member = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);

    $res = $this->withToken(tokenFor($member))->getJson('/api/v1/status/packages')->assertOk();
    $res->assertJsonPath('data.sync.failed', 1)->assertJsonCount(1, 'data.failed')
        ->assertJsonPath('data.failed.0.name', 'a/broken');
});
