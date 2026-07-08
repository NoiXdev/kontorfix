<?php

use App\Enums\WebhookEvent;
use App\Models\Organization;
use App\Models\Webhook;
use Illuminate\Support\Facades\DB;

it('stores an outgoing webhook with subscribed events and a delivery log', function () {
    $org = Organization::factory()->create();
    $wh = Webhook::factory()->for($org)->create([
        'url' => 'https://hooks.example.com/kfx',
        'secret' => 'shhh',
        'events' => [WebhookEvent::PackageSynced->value, WebhookEvent::SyncFailed->value],
    ]);

    $wh->deliveries()->create([
        'event' => WebhookEvent::PackageSynced->value,
        'payload' => ['package' => 'acme/demo'],
        'status_code' => 200,
        'success' => true,
        'attempts' => 1,
    ]);

    expect($wh->secret)->toBe('shhh')
        ->and($wh->events)->toContain('package.synced')
        ->and($wh->deliveries()->first()->success)->toBeTrue();
});

it('encrypts the webhook secret at rest', function () {
    $wh = Webhook::factory()->create(['secret' => 'plaintext']);
    $raw = DB::table('webhooks')->where('id', $wh->id)->value('secret');
    expect($raw)->not->toBe('plaintext')->and($wh->fresh()->secret)->toBe('plaintext');
});

it('reports which events a webhook subscribes to', function () {
    $wh = Webhook::factory()->create(['events' => [WebhookEvent::PackageSynced->value]]);
    expect($wh->subscribesTo(WebhookEvent::PackageSynced))->toBeTrue()
        ->and($wh->subscribesTo(WebhookEvent::SyncFailed))->toBeFalse();
});
