<?php

namespace App\Listeners;

use App\Enums\WebhookEvent;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Jobs\DeliverWebhook;
use App\Models\Package;
use App\Models\Webhook;

class DispatchOutgoingWebhooks
{
    public function onSynced(PackageSynced $event): void
    {
        $this->fanOut(WebhookEvent::PackageSynced, $this->payload($event->package, WebhookEvent::PackageSynced));
    }

    public function onFailed(PackageSyncFailed $event): void
    {
        $payload = $this->payload($event->package, WebhookEvent::SyncFailed);
        $payload['error'] = $event->error;
        $this->fanOut(WebhookEvent::SyncFailed, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * TODO(multi-tenant): In the operator model, all webhooks are system-wide operator
     * hooks, so they receive every package event. Once customer orgs can create their
     * own webhooks, this must be restricted to the package's organization (via its
     * groups) so package names don't leak between tenants.
     */
    private function fanOut(WebhookEvent $event, array $payload): void
    {
        Webhook::where('enabled', true)->get()
            ->filter(fn (Webhook $w) => $w->subscribesTo($event))
            ->each(fn (Webhook $w) => DeliverWebhook::dispatch($w, $event->value, $payload));
    }

    /** @return array<string, mixed> */
    private function payload(Package $package, WebhookEvent $event): array
    {
        return [
            'event' => $event->value,
            'package' => ['name' => $package->name, 'type' => $package->type->value, 'sync_status' => $package->sync_status->value],
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
