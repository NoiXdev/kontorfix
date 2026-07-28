<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
});

it('creates, updates and lists registries', function () {
    $this->withToken($this->plain)->postJson('/api/v1/groups', [
        'name' => 'Acme',
        'slug' => 'acme',
        'public' => false,
        'organization_id' => $this->org->id,
    ])->assertCreated()->assertJsonPath('data.slug', 'acme');

    $group = Group::firstWhere('slug', 'acme');

    $this->withToken($this->plain)->putJson("/api/v1/groups/{$group->id}", [
        'name' => 'Acme Corp', 'public' => true,
    ])->assertOk()->assertJsonPath('data.name', 'Acme Corp');

    $this->withToken($this->plain)->getJson('/api/v1/groups')
        ->assertOk()->assertJsonPath('data.0.name', 'Acme Corp');
});

it('deletes a registry', function () {
    $group = Group::factory()->create(['organization_id' => $this->org->id]);
    $this->withToken($this->plain)->deleteJson("/api/v1/groups/{$group->id}")->assertNoContent();
    expect(Group::find($group->id))->toBeNull();
});
