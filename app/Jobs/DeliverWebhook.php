<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @param array<string, mixed> $payload */
    public function __construct(public Webhook $webhook, public string $event, public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES);
        $headers = ['X-Kontorfix-Event' => $this->event, 'Content-Type' => 'application/json'];
        if ($this->webhook->secret) {
            $headers['X-Kontorfix-Signature'] = 'sha256='.hash_hmac('sha256', (string) $body, $this->webhook->secret);
        }

        $response = Http::timeout(15)->withHeaders($headers)->withBody((string) $body, 'application/json')->post($this->webhook->url);

        $delivery = new WebhookDelivery([
            'event' => $this->event,
            'payload' => $this->payload,
            'status_code' => $response->status(),
            'success' => $response->successful(),
            'attempts' => $this->job?->attempts() ?? 1,
            'error' => $response->successful() ? null : ('HTTP '.$response->status()),
            'delivered_at' => now(),
        ]);
        $this->webhook->deliveries()->save($delivery);

        if (! $response->successful()) {
            throw new RuntimeException("Webhook delivery to {$this->webhook->url} failed with {$response->status()}.");
        }
    }
}
