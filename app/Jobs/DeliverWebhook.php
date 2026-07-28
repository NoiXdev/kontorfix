<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Upstream\UrlSafety;
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
        // SSRF-Schutz: Ziel-URL erst unmittelbar vor dem Versand prüfen (auch bei
        // nachträglich geänderter Konfiguration) — sonst wäre status_code/success
        // der Delivery ein Orakel für internen Port-/Service-Scan (z.B. 169.254.169.254
        // oder [::1]). Kein Retry: einmalig als blockiert protokollieren und beenden,
        // statt die Exception zu werfen, die sonst einen Retry-Sturm auslösen würde.
        if (! UrlSafety::isSafeResolving($this->webhook->url)) {
            $this->webhook->deliveries()->save(new WebhookDelivery([
                'event' => $this->event,
                'payload' => $this->payload,
                'status_code' => null,
                'success' => false,
                'attempts' => $this->job?->attempts() ?? 1,
                'error' => 'Ziel blockiert (SSRF-Schutz): interne/reservierte Adresse.',
                'delivered_at' => now(),
            ]));

            return;
        }

        $body = json_encode($this->payload, JSON_UNESCAPED_SLASHES);
        $headers = ['X-Kontorfix-Event' => $this->event, 'Content-Type' => 'application/json'];
        if ($this->webhook->secret) {
            $headers['X-Kontorfix-Signature'] = 'sha256='.hash_hmac('sha256', (string) $body, $this->webhook->secret);
        }

        // Keine Redirects folgen — der signierte POST darf nur den konfigurierten Host
        // treffen, nicht via 302 auf eine andere (evtl. interne) Adresse umgelenkt werden.
        $response = Http::timeout(15)->withoutRedirecting()->withHeaders($headers)
            ->withBody((string) $body, 'application/json')->post($this->webhook->url);

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
