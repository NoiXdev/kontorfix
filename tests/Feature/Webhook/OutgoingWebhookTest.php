<?php

use App\Enums\WebhookEvent;
use App\Events\PackageSynced;
use App\Jobs\DeliverWebhook;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues a delivery for each subscribed enabled webhook when a package syncs', function () {
    Queue::fake();
    $org = Organization::factory()->create();
    Webhook::factory()->for($org)->create(['events' => [WebhookEvent::PackageSynced->value], 'enabled' => true]);
    Webhook::factory()->for($org)->create(['events' => [WebhookEvent::SyncFailed->value], 'enabled' => true]);   // wrong event
    Webhook::factory()->for($org)->create(['events' => [WebhookEvent::PackageSynced->value], 'enabled' => false]); // disabled

    PackageSynced::dispatch(Package::factory()->create());

    Queue::assertPushed(DeliverWebhook::class, 1);
});

it('delivers a signed payload and logs a successful delivery', function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);
    $wh = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => 'sec', 'events' => [WebhookEvent::PackageSynced->value]]);

    (new DeliverWebhook($wh, WebhookEvent::PackageSynced->value, ['event' => 'package.synced', 'package' => ['name' => 'acme/demo']]))->handle();

    Http::assertSent(function ($r) {
        $expected = 'sha256='.hash_hmac('sha256', $r->body(), 'sec');

        return $r->hasHeader('X-Kontorfix-Signature', $expected) && $r->hasHeader('X-Kontorfix-Event', 'package.synced');
    });
    $delivery = $wh->deliveries()->latest()->first();
    expect($delivery->success)->toBeTrue()->and($delivery->status_code)->toBe(200);
});

it('logs a failed delivery and rethrows for retry on non-2xx', function () {
    Http::fake(['hooks.test/*' => Http::response('boom', 500)]);
    $wh = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => 'sec', 'events' => [WebhookEvent::PackageSynced->value]]);

    expect(fn () => (new DeliverWebhook($wh, 'package.synced', ['event' => 'package.synced']))->handle())
        ->toThrow(RuntimeException::class);
    expect($wh->deliveries()->latest()->first()->success)->toBeFalse();
});

it('delivers without a signature header when the webhook has no secret', function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);
    $wh = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => null, 'events' => [WebhookEvent::PackageSynced->value]]);

    (new DeliverWebhook($wh, 'package.synced', ['event' => 'package.synced']))->handle();
    Http::assertSent(fn ($r) => ! $r->hasHeader('X-Kontorfix-Signature'));
});
