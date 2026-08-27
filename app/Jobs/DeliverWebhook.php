<?php

namespace App\Jobs;

use App\Events\WebhookDeliveryFailed;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Upstream\UrlSafety;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class DeliverWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Explicit, and equal to what the supervisor default used to hand this job.
     *
     * config/horizon.php raises `supervisor-1.timeout` to SyncPackage::TIMEOUT (900s)
     * because SyncPackage's own kill paths need it there. `Worker::timeoutForJob()` gives
     * the supervisor value to every job that declares none, so without this line raising it
     * for one job would have quietly given a hung webhook delivery a fifteen-minute worker
     * alarm as well. The HTTP call below is capped at 15s; 60s covers it plus the delivery
     * row write with room to spare.
     */
    public int $timeout = 60;

    /** @param array<string, mixed> $payload */
    public function __construct(public Webhook $webhook, public string $event, public array $payload) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(): void
    {
        // SSRF protection: check the target URL right before sending (even if the
        // configuration was changed afterwards) — otherwise the delivery's
        // status_code/success would become an oracle for scanning internal ports/
        // services (e.g. 169.254.169.254 or [::1]). No retry: log as blocked once
        // and stop, instead of throwing the exception, which would otherwise trigger
        // a retry storm.
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

        // Do not follow redirects — the signed POST may only hit the configured host,
        // not be redirected via 302 to another (possibly internal) address.
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

    public function failed(Throwable $e): void
    {
        // Only after the last of $tries attempts — one event per permanently undelivered
        // webhook, not one per retry.
        WebhookDeliveryFailed::dispatch($this->webhook, $this->event, $e->getMessage());
    }
}
