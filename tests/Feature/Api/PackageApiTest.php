<?php

use App\Enums\ApiKeyPermission;
use App\Jobs\SyncPackage;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function operatorWriteToken(): string
{
    $org = Organization::factory()->create(['is_operator' => true]);
    $admin = User::factory()->create(['organization_id' => $org->id, 'role' => 'admin']);
    [, $plain] = ApiKey::issue($admin, 'w', ApiKeyPermission::Write);

    return $plain;
}

it('lists and shows packages', function () {
    $plain = operatorWriteToken();
    $package = Package::factory()->create(['name' => 'acme/widget']);

    $this->withToken($plain)->getJson('/api/v1/packages')
        ->assertOk()->assertJsonPath('data.0.name', 'acme/widget');

    $this->withToken($plain)->getJson("/api/v1/packages/{$package->id}")
        ->assertOk()->assertJsonPath('data.name', 'acme/widget');
});

it('creates a package and dispatches a sync', function () {
    Queue::fake();
    $plain = operatorWriteToken();

    $this->withToken($plain)->postJson('/api/v1/packages', [
        'type' => 'composer',
        'name' => 'acme/new',
        'repository_url' => 'https://github.com/acme/new.git',
    ])->assertCreated()->assertJsonPath('data.name', 'acme/new');

    Queue::assertPushed(SyncPackage::class);
});

it('triggers a resync', function () {
    Queue::fake();
    $plain = operatorWriteToken();
    $package = Package::factory()->create(['repository_url' => 'https://github.com/acme/w.git']);

    $this->withToken($plain)->postJson("/api/v1/packages/{$package->id}/resync")->assertOk();
    Queue::assertPushed(SyncPackage::class);
});

it('denies members without operator role', function () {
    $org = Organization::factory()->create(['is_operator' => false]);
    $member = User::factory()->create(['organization_id' => $org->id, 'role' => 'member']);
    [, $plain] = ApiKey::issue($member, 'w', ApiKeyPermission::Write);

    $this->withToken($plain)->getJson('/api/v1/packages')->assertForbidden();
});
