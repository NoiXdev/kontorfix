<?php

use App\Enums\NotificationEvent;

it('carries the two event values the listeners record', function () {
    expect(NotificationEvent::SyncFailed->value)->toBe('sync.failed')
        ->and(NotificationEvent::WebhookDeliveryFailed->value)->toBe('webhook.delivery_failed');
});

it('gives every case a German label', function () {
    foreach (NotificationEvent::cases() as $case) {
        expect($case->label())->not->toBe('')->and($case->label())->not->toBe($case->value);
    }
});

it('publishes value and label for the frontend, one entry per case', function () {
    $meta = NotificationEvent::metadata();

    expect($meta)->toHaveCount(count(NotificationEvent::cases()))
        ->and($meta[0])->toHaveKeys(['value', 'label']);
});
