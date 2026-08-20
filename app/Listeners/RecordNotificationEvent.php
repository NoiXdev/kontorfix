<?php

namespace App\Listeners;

use App\Enums\NotificationEvent;
use App\Events\PackageSyncFailed;
use App\Events\WebhookDeliveryFailed;
use App\Models\NotificationEventRecord;
use App\Models\Organization;

/**
 * Captures a failure the moment it happens.
 *
 * Everything is filed under the operator organization. A package can belong to groups in
 * several organizations, and `sync_error` can carry repository detail the other tenants
 * have no business reading — so the digest does not fan out across tenants. The same
 * restraint is recorded as a TODO on DispatchOutgoingWebhooks; when customer
 * organizations get their own recipients, this is the method that changes.
 */
class RecordNotificationEvent
{
    public function onSyncFailed(PackageSyncFailed $event): void
    {
        $this->record(
            NotificationEvent::SyncFailed,
            $event->package::class,
            $event->package->id,
            $event->package->name,
            $event->error,
        );
    }

    public function onWebhookDeliveryFailed(WebhookDeliveryFailed $event): void
    {
        $this->record(
            NotificationEvent::WebhookDeliveryFailed,
            $event->webhook::class,
            $event->webhook->id,
            $event->webhook->url,
            $event->error,
        );
    }

    private function record(NotificationEvent $type, string $subjectType, string $subjectId, string $label, string $summary): void
    {
        $operator = Organization::where('is_operator', true)->first();

        // A fresh instance before setup has no operator organization. Recording against
        // nobody would create rows no digest can ever own, so the failure is simply not
        // recorded — it is still in the logs and on the package row.
        if ($operator === null) {
            return;
        }

        NotificationEventRecord::create([
            'organization_id' => $operator->id,
            'type' => $type->value,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_label' => $label,
            'summary' => $summary,
            'occurred_at' => now(),
        ]);
    }
}
