<?php

use App\Models\NotificationEventDelivery;
use App\Models\NotificationEventRecord;
use App\Models\NotificationRecipient;
use App\Models\Organization;

// Finding 3: nothing in the app ever deletes a NotificationEventDelivery row directly — the
// database-level cascadeOnDelete() in the migration is the only thing removing it once its
// event or recipient is gone. That matters in particular for NotificationEventRecord's
// MassPrunable pruning, which deletes rows in bulk and relies entirely on the cascade to
// avoid leaving orphaned delivery rows behind. Prove the cascade actually fires by deleting
// each side and asserting the delivery row goes with it.
//
// To prove these can fail: drop cascadeOnDelete() from
// database/migrations/2026_08_20_020000_create_notification_event_deliveries_table.php
// (both foreignUuid() lines) and re-run — RefreshDatabase runs migrate:fresh once per test
// process, so the mutation must be made in the migration file itself; an ALTER TABLE
// against the live schema would be silently undone by the next migrate:fresh.

function orgForCascadeTest(): Organization
{
    return Organization::factory()->create(['is_operator' => true]);
}

function deliveryFixture(Organization $org): array
{
    $event = NotificationEventRecord::create([
        'organization_id' => $org->id,
        'type' => 'sync.failed',
        'subject_label' => 'acme/demo',
        'summary' => 'timeout',
        'occurred_at' => now(),
    ]);

    $recipient = NotificationRecipient::create([
        'organization_id' => $org->id,
        'email' => 'ops@example.test',
        'events' => ['sync.failed'],
        'enabled' => true,
    ]);

    $delivery = NotificationEventDelivery::create([
        'notification_event_id' => $event->id,
        'notification_recipient_id' => $recipient->id,
        'delivered_at' => now(),
    ]);

    return [$event, $recipient, $delivery];
}

it('cascades a NotificationEventRecord delete to its delivery rows', function () {
    $org = orgForCascadeTest();
    [$event, , $delivery] = deliveryFixture($org);

    $event->delete();

    expect(NotificationEventDelivery::whereKey($delivery->id)->exists())->toBeFalse();
});

it('cascades a NotificationRecipient delete to its delivery rows', function () {
    $org = orgForCascadeTest();
    [, $recipient, $delivery] = deliveryFixture($org);

    $recipient->delete();

    expect(NotificationEventDelivery::whereKey($delivery->id)->exists())->toBeFalse();
});
