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

// The partition was global across hooks, which left the URL of *any* hook a lever against
// every other hook's evidence — and the rejected cap being the smaller of the two made it
// cheap. The property: a flood against one hook cannot erase the record of a real event
// on another.

function recordFor(?IncomingWebhook $hook, bool $valid): IncomingWebhookEvent
{
    return IncomingWebhookEvent::record([
        'incoming_webhook_id' => $hook?->id,
        'provider' => 'github',
        'repo_url' => $valid ? 'https://github.com/acme/demo.git' : null,
        'signature_valid' => $valid,
        'matched_packages' => 0,
        'status_code' => $valid ? 200 : 401,
        'ip' => '203.0.113.9',
        'payload' => ['x' => 1],
    ]);
}

function makeHook(string $name): IncomingWebhook
{
    return IncomingWebhook::create(['name' => $name, 'provider' => 'github', 'enabled' => true, 'secret' => 'topsecret-'.$name]);
}

it('does not let a flood against one hook evict another hook rejected records', function () {
    $victim = makeHook('victim');
    $target = makeHook('target');

    // The victim's own evidence: someone tried an unsigned delivery against their hook.
    $evidence = recordFor($victim, false);

    // A flood against a different hook, far past the rejected cap.
    foreach (range(1, IncomingWebhookEvent::REJECTED_RETENTION * 3) as $ignored) {
        recordFor($target, false);
    }

    expect(IncomingWebhookEvent::find($evidence->id))->not->toBeNull()
        ->and(IncomingWebhookEvent::where('incoming_webhook_id', $victim->id)->count())->toBe(1);
});

it('does not let a flood against one hook evict another hook verified deliveries', function () {
    $victim = makeHook('victim');
    $target = makeHook('target');

    $delivered = collect(range(1, 5))->map(fn () => recordFor($victim, true));

    foreach (range(1, IncomingWebhookEvent::RETENTION * 2) as $ignored) {
        recordFor($target, false);
    }
    foreach (range(1, IncomingWebhookEvent::RETENTION + 20) as $ignored) {
        recordFor($target, true);
    }

    expect(IncomingWebhookEvent::whereIn('id', $delivered->pluck('id'))->count())->toBe(5);
});

it('keeps the legacy shared endpoint as its own partition', function () {
    $shared = recordFor(null, false);
    $hook = makeHook('hookish');

    foreach (range(1, IncomingWebhookEvent::REJECTED_RETENTION * 3) as $ignored) {
        recordFor($hook, false);
    }

    expect(IncomingWebhookEvent::find($shared->id))->not->toBeNull();
});

it('still bounds one hook own rejected partition', function () {
    $hook = makeHook('noisy');

    foreach (range(1, IncomingWebhookEvent::REJECTED_RETENTION + 40) as $ignored) {
        recordFor($hook, false);
    }

    expect(IncomingWebhookEvent::where('incoming_webhook_id', $hook->id)->count())
        ->toBe(IncomingWebhookEvent::REJECTED_RETENTION);
});

it('keeps an excerpt of an oversized payload instead of throwing the record away', function () {
    $event = IncomingWebhookEvent::record([
        'provider' => 'github',
        'signature_valid' => true,
        'matched_packages' => 0,
        'status_code' => 200,
        'ip' => '203.0.113.9',
        'payload' => ['head_commit' => ['message' => 'chore: bump'], 'blob' => str_repeat('A', IncomingWebhookEvent::MAX_PAYLOAD_BYTES + 1000)],
    ]);

    $stored = $event->fresh()->payload;

    // A large but legitimate push must not leave an audit row that says only
    // "there was something here".
    expect($stored['_truncated'])->toBeTrue()
        ->and($stored['_excerpt'])->toContain('chore: bump')
        ->and(strlen($stored['_excerpt']))->toBe(IncomingWebhookEvent::PAYLOAD_EXCERPT_BYTES)
        ->and(strlen((string) json_encode($stored)))->toBeLessThanOrEqual(IncomingWebhookEvent::MAX_PAYLOAD_BYTES);
});
