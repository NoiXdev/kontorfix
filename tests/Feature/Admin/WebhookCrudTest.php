<?php

use App\Enums\UserRole;
use App\Enums\WebhookEvent;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;

it('lists webhooks with their recent deliveries for admins', function () {
    $wh = Webhook::factory()->create();
    $wh->deliveries()->create(['event' => WebhookEvent::PackageSynced->value, 'payload' => [], 'status_code' => 200, 'success' => true, 'attempts' => 1, 'delivered_at' => now()]);

    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/webhooks/Index')->has('webhooks', 1)->has('incoming'));
});

it('creates a webhook subscribed to events', function () {
    $org = Organization::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->post('/admin/webhooks', [
            'url' => 'https://hooks.example.com/kfx',
            'secret' => 'topsecret',
            'events' => ['package.synced', 'sync.failed'],
        ])->assertRedirect();

    $wh = Webhook::where('url', 'https://hooks.example.com/kfx')->firstOrFail();
    expect($wh->events)->toContain('package.synced', 'sync.failed')
        ->and($wh->secret)->toBe('topsecret');
});

it('validates the url and events', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $this->actingAs($admin)->post('/admin/webhooks', ['url' => 'not-a-url', 'events' => ['package.synced']])
        ->assertSessionHasErrors('url');
    $this->actingAs($admin)->post('/admin/webhooks', ['url' => 'https://x.test/h', 'events' => ['nonsense']])
        ->assertSessionHasErrors('events.0');
    $this->actingAs($admin)->post('/admin/webhooks', ['url' => 'https://x.test/h', 'events' => []])
        ->assertSessionHasErrors('events');
});

it('never exposes the secret in the index payload', function () {
    Webhook::factory()->create(['secret' => 'topsecret']);
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/webhooks')
        ->assertInertia(fn ($page) => $page->has('webhooks.0', fn ($w) => $w
            ->hasAll(['id', 'url', 'events', 'enabled', 'has_secret', 'recent_deliveries'])
            ->missing('secret')->etc()));
});

it('deletes a webhook', function () {
    $wh = Webhook::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->delete("/admin/webhooks/{$wh->id}")->assertRedirect();
    expect(Webhook::find($wh->id))->toBeNull();
});

it('forbids members', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/webhooks')->assertForbidden();
});
