<?php

use App\Enums\UserRole;
use App\Models\IncomingWebhook;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;

beforeEach(function () {
    // A customer-org admin: reaches the scoped registry console, but never the
    // instance-wide webhook management.
    $this->custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->super = User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
});

it('denies a customer-org admin the outgoing webhook surface', function () {
    $webhook = Webhook::factory()->create();

    $this->actingAs($this->custAdmin)->get('/admin/webhooks')->assertForbidden();
    $this->actingAs($this->custAdmin)->post('/admin/webhooks', [
        'url' => 'https://hook.example.test', 'events' => ['package.synced'],
    ])->assertForbidden();
    $this->actingAs($this->custAdmin)->delete("/admin/webhooks/{$webhook->id}")->assertForbidden();
    expect(Webhook::find($webhook->id))->not->toBeNull();
});

it('denies a customer-org admin the incoming webhook surface', function () {
    $hook = IncomingWebhook::create(['name' => 'gh', 'provider' => 'github', 'secret' => 'seekrit', 'enabled' => true]);

    $this->actingAs($this->custAdmin)->post('/admin/incoming-webhooks', ['name' => 'x', 'provider' => 'github'])->assertForbidden();
    $this->actingAs($this->custAdmin)->post("/admin/incoming-webhooks/{$hook->id}/regenerate")->assertForbidden();
    $this->actingAs($this->custAdmin)->delete("/admin/incoming-webhooks/{$hook->id}")->assertForbidden();
    expect(IncomingWebhook::find($hook->id))->not->toBeNull();
});

it('allows the super-admin through the webhook surface', function () {
    $this->actingAs($this->super)->get('/admin/webhooks')->assertOk();
});
