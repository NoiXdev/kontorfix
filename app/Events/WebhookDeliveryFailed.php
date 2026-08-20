<?php

namespace App\Events;

use App\Models\Webhook;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A webhook that has exhausted its retries.
 *
 * Deliberately not broadcast. PackageSyncFailed goes to the operator channel because a
 * sync failure changes what the admin listing shows; a webhook that could not be
 * delivered changes nothing on screen, and the digest is the point of recording it.
 */
class WebhookDeliveryFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Webhook $webhook,
        public readonly string $event,
        public readonly string $error,
    ) {}
}
