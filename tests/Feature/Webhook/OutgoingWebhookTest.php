<?php

use App\Enums\WebhookEvent;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Jobs\DeliverWebhook;
use App\Jobs\SyncPackage;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Webhook;
use Illuminate\Support\Facades\Event;
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

it('fires sync.failed only once, from the job failed() hook (not per retry)', function () {
    Event::fake([PackageSyncFailed::class]);
    $pkg = Package::factory()->create(['repository_url' => 'file:///does/not/exist-'.uniqid()]);

    // handle() throws (and marks it Failed), but does NOT fire the event — that's what failed() does.
    expect(fn () => (new SyncPackage($pkg))->handle())->toThrow(RuntimeException::class);
    Event::assertNotDispatched(PackageSyncFailed::class);

    // The queue worker calls failed() after the final failure -> exactly one event.
    (new SyncPackage($pkg))->failed(new RuntimeException('boom'));
    Event::assertDispatched(PackageSyncFailed::class, 1);
});

it('does not follow redirects when delivering a webhook', function () {
    Http::fake(['hooks.test/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/x'])]);
    $wh = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => 'sec', 'events' => [WebhookEvent::PackageSynced->value]]);

    // 302 counts as a failure (not 2xx) -> rethrow, and the redirect is never followed.
    expect(fn () => (new DeliverWebhook($wh, 'package.synced', ['event' => 'package.synced']))->handle())
        ->toThrow(RuntimeException::class);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '169.254'));
});
