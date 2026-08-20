<?php

use App\Events\PackageSyncFailed;
use App\Events\WebhookDeliveryFailed;
use App\Models\NotificationEventRecord;
use App\Models\Organization;
use App\Models\Package;
use App\Models\Webhook;

it('records a sync failure against the operator organization', function () {
    // The non-operator organization is created first on purpose: Postgres returns an
    // unordered scan of a freshly-migrated table in insertion order, and Organization
    // uses time-ordered UUIDv7 primary keys. Creating the operator first would make
    // `Organization::first()` return the operator anyway, so a careless implementation
    // that drops the `is_operator` filter would pass this test by accident.
    Organization::factory()->create(['is_operator' => false]);
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->create(['name' => 'acme/demo']);

    PackageSyncFailed::dispatch($package, 'auth denied');

    $record = NotificationEventRecord::sole();
    expect($record->organization_id)->toBe($operator->id)
        ->and($record->type)->toBe('sync.failed')
        ->and($record->subject_label)->toBe('acme/demo')
        ->and($record->summary)->toBe('auth denied')
        ->and($record->notified_at)->toBeNull();
});

it('records a webhook delivery failure with the webhook url as its subject', function () {
    Organization::factory()->create(['is_operator' => true]);
    $webhook = Webhook::factory()->create(['url' => 'https://hooks.example.test/a']);

    WebhookDeliveryFailed::dispatch($webhook, 'sync.failed', '502 Bad Gateway');

    $record = NotificationEventRecord::sole();
    expect($record->type)->toBe('webhook.delivery_failed')
        ->and($record->subject_label)->toBe('https://hooks.example.test/a')
        ->and($record->summary)->toContain('502');
});

it('records nothing when no operator organization exists', function () {
    $package = Package::factory()->create();

    PackageSyncFailed::dispatch($package, 'auth denied');

    expect(NotificationEventRecord::count())->toBe(0);
});
