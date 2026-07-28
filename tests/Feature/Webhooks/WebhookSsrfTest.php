<?php

use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('refuses to deliver to an internal target and records it as blocked', function () {
    Http::fake(); // nichts darf real rausgehen
    $webhook = Webhook::factory()->create(['url' => 'http://127.0.0.1/hook', 'events' => ['package.synced']]);

    DeliverWebhook::dispatchSync($webhook, 'package.synced', ['name' => 'acme/x']);

    Http::assertNothingSent();
    // Es muss eine Delivery mit klarem Blockiert-Status geben (kein echter HTTP-Statuscode als Orakel).
    $delivery = WebhookDelivery::where('webhook_id', $webhook->id)->latest()->first();
    expect($delivery)->not->toBeNull();
    expect($delivery->success)->toBeFalse();
    expect($delivery->status_code)->toBeNull();
});

it('still delivers to a public target', function () {
    Http::fake(['https://hooks.example/*' => Http::response('ok', 200)]);
    $webhook = Webhook::factory()->create(['url' => 'https://hooks.example/hook', 'events' => ['package.synced']]);

    DeliverWebhook::dispatchSync($webhook, 'package.synced', ['name' => 'acme/x']);

    Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://hooks.example/'));
});
