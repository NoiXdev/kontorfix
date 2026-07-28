<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
});

it('creates, lists and deletes webhooks without leaking the secret', function () {
    $res = $this->withToken($this->plain)->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.acme.test/x',
        'secret' => 'supersecret',
        'events' => ['package.synced'],
    ])->assertCreated();

    expect($res->json('data'))->not->toHaveKey('secret');
    $res->assertJsonPath('data.has_secret', true);

    $id = $res->json('data.id');
    $this->withToken($this->plain)->getJson('/api/v1/webhooks')->assertOk();
    $this->withToken($this->plain)->deleteJson("/api/v1/webhooks/{$id}")->assertNoContent();
    expect(Webhook::find($id))->toBeNull();
});

it('returns status counters', function () {
    $this->withToken($this->plain)->getJson('/api/v1/status')
        ->assertOk()->assertJsonStructure(['data' => ['packages', 'sync']]);
});
