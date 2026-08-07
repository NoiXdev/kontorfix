<?php

use App\Models\IncomingWebhook;
use App\Models\IncomingWebhookEvent;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * A09 — the incoming-webhook audit is the only place a failed signature verification is
 * recorded anywhere in the application, and it was pruned as one global ring. Anyone
 * holding a real hook URL but not its secret (an admin of the customer's git-host
 * organization, where the URL is visible in the webhook configuration UI) could post a few
 * hundred unsigned requests and evict every genuine delivery — erasing the record of the
 * attack while it was in progress.
 *
 * The property under test: garbage cannot displace verified deliveries.
 */
function recordEvent(bool $valid, ?string $repoUrl = null): IncomingWebhookEvent
{
    return IncomingWebhookEvent::record([
        'provider' => 'github',
        'repo_url' => $repoUrl,
        'signature_valid' => $valid,
        'matched_packages' => 0,
        'status_code' => $valid ? 200 : 401,
        'ip' => '203.0.113.9',
        'payload' => ['x' => 1],
    ]);
}

it('keeps verified deliveries when unsigned requests flood the audit', function () {
    $genuine = recordEvent(true, 'https://github.com/acme/demo.git');

    // Comfortably past the overall retention limit.
    foreach (range(1, IncomingWebhookEvent::RETENTION + 60) as $ignored) {
        recordEvent(false);
    }

    expect(IncomingWebhookEvent::find($genuine->id))->not->toBeNull()
        ->and(IncomingWebhookEvent::where('signature_valid', true)->count())->toBe(1);
});

it('bounds the rejected partition separately from the verified one', function () {
    foreach (range(1, IncomingWebhookEvent::RETENTION + 60) as $ignored) {
        recordEvent(false);
    }

    expect(IncomingWebhookEvent::where('signature_valid', false)->count())
        ->toBeLessThanOrEqual(IncomingWebhookEvent::REJECTED_RETENTION);
});

it('still prunes verified deliveries down to the overall retention limit', function () {
    foreach (range(1, IncomingWebhookEvent::RETENTION + 10) as $ignored) {
        recordEvent(true);
    }

    expect(IncomingWebhookEvent::where('signature_valid', true)->count())
        ->toBe(IncomingWebhookEvent::RETENTION);
});

it('bounds the stored payload so a flood cannot bloat the table', function () {
    $event = IncomingWebhookEvent::record([
        'provider' => 'github',
        'signature_valid' => false,
        'matched_packages' => 0,
        'status_code' => 401,
        'ip' => '203.0.113.9',
        'payload' => ['blob' => str_repeat('A', IncomingWebhookEvent::MAX_PAYLOAD_BYTES + 1000)],
    ]);

    expect(strlen((string) json_encode($event->fresh()->payload)))
        ->toBeLessThanOrEqual(IncomingWebhookEvent::MAX_PAYLOAD_BYTES);
});

it('protects a real hook audit trail through the http endpoint', function () {
    $hook = IncomingWebhook::create(['name' => 'gh', 'provider' => 'github', 'enabled' => true, 'secret' => 'topsecret']);
    $payload = ['repository' => ['clone_url' => 'https://github.com/acme/demo.git']];
    $signature = 'sha256='.hash_hmac('sha256', (string) json_encode($payload), 'topsecret');

    $this->withHeaders(['X-Hub-Signature-256' => $signature])
        ->postJson("/webhooks/github/{$hook->id}", $payload)->assertOk();

    // A burst of unsigned deliveries to the same hook. The throttle is bypassed because
    // the point is the audit ring, not the rate limit — 60/min only slows the flood down.
    $this->flushHeaders(); // withHeaders() is sticky — drop the valid signature.
    $this->withoutMiddleware(ThrottleRequests::class);
    foreach (range(1, IncomingWebhookEvent::REJECTED_RETENTION + 20) as $ignored) {
        $this->postJson("/webhooks/github/{$hook->id}", $payload)->assertUnauthorized();
    }

    expect(IncomingWebhookEvent::where('signature_valid', true)->count())->toBe(1);
});
