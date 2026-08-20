<?php

use App\Enums\NotificationEvent;
use App\Models\NotificationEventRecord;
use App\Models\NotificationRecipient;
use App\Models\Organization;
use Illuminate\Database\QueryException;

it('stores a recipient with its subscribed events', function () {
    $org = Organization::factory()->create();

    $recipient = NotificationRecipient::create([
        'organization_id' => $org->id,
        'email' => 'ops@example.test',
        'name' => 'Betrieb',
        'events' => [NotificationEvent::SyncFailed->value],
        'enabled' => true,
    ]);

    expect($recipient->fresh()->events)->toBe(['sync.failed']);
});

it('answers which events a recipient subscribes to', function () {
    $recipient = new NotificationRecipient(['events' => ['sync.failed']]);

    expect($recipient->subscribesTo(NotificationEvent::SyncFailed))->toBeTrue()
        ->and($recipient->subscribesTo(NotificationEvent::WebhookDeliveryFailed))->toBeFalse();
});

it('treats a recipient with no events as subscribing to nothing', function () {
    $recipient = new NotificationRecipient(['events' => null]);

    expect($recipient->subscribesTo(NotificationEvent::SyncFailed))->toBeFalse();
});

it('refuses the same address twice within one organization', function () {
    $org = Organization::factory()->create();
    $attributes = ['organization_id' => $org->id, 'email' => 'ops@example.test', 'events' => [], 'enabled' => true];

    NotificationRecipient::create($attributes);

    expect(fn () => NotificationRecipient::create($attributes))
        ->toThrow(QueryException::class);
});

it('allows the same address in two different organizations', function () {
    $a = Organization::factory()->create();
    $b = Organization::factory()->create();

    NotificationRecipient::create(['organization_id' => $a->id, 'email' => 'ops@example.test', 'events' => [], 'enabled' => true]);
    NotificationRecipient::create(['organization_id' => $b->id, 'email' => 'ops@example.test', 'events' => [], 'enabled' => true]);

    expect(NotificationRecipient::count())->toBe(2);
});

it('defaults an organization to the hourly cadence', function () {
    expect(Organization::factory()->create()->fresh()->notification_cadence)->toBe('hourly');
});

it('records an event as unreported until it is marked', function () {
    $org = Organization::factory()->create();

    $record = NotificationEventRecord::create([
        'organization_id' => $org->id,
        'type' => NotificationEvent::SyncFailed->value,
        'subject_label' => 'acme/demo',
        'summary' => 'timeout',
        'occurred_at' => now(),
    ]);

    expect($record->fresh()->notified_at)->toBeNull();
});
