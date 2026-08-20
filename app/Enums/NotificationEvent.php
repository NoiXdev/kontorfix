<?php

namespace App\Enums;

/**
 * The failures a recipient can subscribe to.
 *
 * Deliberately not the same list as WebhookEvent: that enum also carries success events
 * (`package.synced`, `version.released`), which a failure digest has no business mailing.
 * The two lists overlap in meaning, not in purpose, so they stay separate rather than one
 * being filtered down to the other at every call site.
 */
enum NotificationEvent: string
{
    case SyncFailed = 'sync.failed';
    case WebhookDeliveryFailed = 'webhook.delivery_failed';

    /** German, because it is rendered in the admin form and in the mail. */
    public function label(): string
    {
        return match ($this) {
            self::SyncFailed => 'Sync fehlgeschlagen',
            self::WebhookDeliveryFailed => 'Webhook nicht zustellbar',
        };
    }

    /**
     * Shared to the frontend through HandleInertiaRequests, exactly as
     * PackageType::metadata() already is, so the form's checkbox list has one source.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function metadata(): array
    {
        return array_map(
            fn (self $e): array => ['value' => $e->value, 'label' => $e->label()],
            self::cases(),
        );
    }
}
