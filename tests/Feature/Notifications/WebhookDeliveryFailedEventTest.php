<?php

use App\Enums\WebhookEvent;
use App\Events\WebhookDeliveryFailed;
use App\Jobs\DeliverWebhook;
use App\Models\Webhook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

it('announces a delivery failure once, when the job finally fails', function () {
    Event::fake([WebhookDeliveryFailed::class]);
    $webhook = Webhook::factory()->create();

    (new DeliverWebhook($webhook, 'sync.failed', ['x' => 1]))->failed(new RuntimeException('502 Bad Gateway'));

    Event::assertDispatched(WebhookDeliveryFailed::class, function (WebhookDeliveryFailed $e) use ($webhook): bool {
        return $e->webhook->is($webhook)
            && $e->event === 'sync.failed'
            && str_contains($e->error, '502');
    });
    Event::assertDispatchedTimes(WebhookDeliveryFailed::class, 1);
});

it('does not announce a failure while attempts remain', function () {
    Event::fake([WebhookDeliveryFailed::class]);
    Http::fake(['hooks.test/*' => Http::response('boom', 500)]);
    $webhook = Webhook::factory()->create(['url' => 'https://hooks.test/kfx', 'secret' => 'sec', 'events' => [WebhookEvent::PackageSynced->value]]);

    // handle() throwing is a retry, not a final failure — only failed() is terminal.
    // toThrow(Throwable::class) does not work here: Pest's toThrow() only does an
    // instanceof check when the argument is a concrete, instantiable class; Throwable
    // is an interface (class_exists() is false for it), so Pest falls back to treating
    // the string as an expected substring of the exception message instead — which this
    // message does not contain. RuntimeException::class is what handle() actually
    // throws (and what OutgoingWebhookTest asserts against), so assert on that.
    expect(fn () => (new DeliverWebhook($webhook, WebhookEvent::PackageSynced->value, ['event' => 'package.synced']))->handle())->toThrow(RuntimeException::class);

    Event::assertNotDispatched(WebhookDeliveryFailed::class);
});
