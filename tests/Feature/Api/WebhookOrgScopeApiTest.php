<?php

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;

beforeEach(function () {
    // Operator-org admin: isSuperAdmin() (grandfathered), the only role that reaches
    // /api/v1/webhooks at all (gated by the `super` middleware).
    $this->op = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->op->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
});

it('lists webhooks across every organization for a super-reaching caller', function () {
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();
    Webhook::factory()->create(['organization_id' => $orgA->id]);
    Webhook::factory()->create(['organization_id' => $orgB->id]);

    $res = $this->withToken($this->plain)->getJson('/api/v1/webhooks')->assertOk();

    expect($res->json('data'))->toHaveCount(2);
});

it('attributes a created webhook to an explicitly requested organization', function () {
    $target = Organization::factory()->create();

    $res = $this->withToken($this->plain)->postJson('/api/v1/webhooks', [
        'organization_id' => $target->id,
        'url' => 'https://hooks.acme.test/scoped',
        'events' => ['package.synced'],
    ])->assertCreated();

    $webhook = Webhook::findOrFail($res->json('data.id'));
    expect($webhook->organization_id)->toBe($target->id);
});

it('defaults a created webhook to the caller\'s own organization when none is given', function () {
    $res = $this->withToken($this->plain)->postJson('/api/v1/webhooks', [
        'url' => 'https://hooks.acme.test/default',
        'events' => ['package.synced'],
    ])->assertCreated();

    $webhook = Webhook::findOrFail($res->json('data.id'));
    expect($webhook->organization_id)->toBe($this->op->id);
});

it('allows a super-reaching caller to delete a legacy null-organization webhook', function () {
    $legacy = Webhook::factory()->create(['organization_id' => null]);

    $this->withToken($this->plain)->deleteJson("/api/v1/webhooks/{$legacy->id}")->assertNoContent();
    expect(Webhook::find($legacy->id))->toBeNull();
});
