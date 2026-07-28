<?php

use App\Enums\WebhookEvent;
use App\Models\Package;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Tests\Support\FixtureRepo;

beforeEach(function () {
    config(['kontorfix.incoming_webhook_secret' => 'topsecret']);
    // Sync queue: the incoming request executes SyncPackage (and the outgoing delivery)
    // synchronously, so the whole flow runs through within a single request.
    config(['queue.default' => 'sync']);
});

function githubPushFlow(string $cloneUrl): array
{
    return ['repository' => ['clone_url' => $cloneUrl]];
}

it('runs the full chain: github push -> resync -> package.synced -> signed outgoing delivery', function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);

    $repoUrl = 'file://'.FixtureRepo::make();
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => $repoUrl]);
    $wh = Webhook::factory()->create([
        'url' => 'https://hooks.test/kfx',
        'secret' => 'sec',
        'events' => [WebhookEvent::PackageSynced->value],
    ]);

    $payload = githubPushFlow($repoUrl);
    $this->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret')])
        ->postJson('/webhooks/github', $payload)->assertOk()->assertJsonPath('synced', 1);

    // The package was actually synced (versions from the fixture repo).
    expect($pkg->fresh()->versions()->count())->toBeGreaterThan(0);

    // And a signed delivery to the subscribed webhook happened + was logged.
    Http::assertSent(function ($r) {
        return $r->hasHeader('X-Kontorfix-Event', 'package.synced')
            && $r->hasHeader('X-Kontorfix-Signature', 'sha256='.hash_hmac('sha256', $r->body(), 'sec'));
    });
    expect($wh->deliveries()->where('event', 'package.synced')->where('success', true)->exists())->toBeTrue();
});

it('delivers a sync.failed event to a subscriber when a resync fails', function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);

    $repoUrl = 'file:///does/not/exist-'.uniqid();
    Package::factory()->create(['name' => 'acme/broken', 'repository_url' => $repoUrl]);
    $wh = Webhook::factory()->create([
        'url' => 'https://hooks.test/kfx',
        'secret' => 'sec',
        'events' => [WebhookEvent::SyncFailed->value],
    ]);

    $payload = githubPushFlow($repoUrl);
    // The sync fails (repo doesn't exist) and will ultimately fail after the retries;
    // the incoming request itself remains successful (it only queues the job).
    $this->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret')])
        ->postJson('/webhooks/github', $payload);

    expect($wh->deliveries()->where('event', 'sync.failed')->exists())->toBeTrue();
});

it('does not deliver to a webhook that is disabled or not subscribed', function () {
    Http::fake(['hooks.test/*' => Http::response('', 200)]);

    $repoUrl = 'file://'.FixtureRepo::make();
    Package::factory()->create(['name' => 'acme/demo', 'repository_url' => $repoUrl]);
    Webhook::factory()->create(['url' => 'https://hooks.test/off', 'events' => [WebhookEvent::PackageSynced->value], 'enabled' => false]);
    Webhook::factory()->create(['url' => 'https://hooks.test/other', 'events' => [WebhookEvent::VersionReleased->value], 'enabled' => true]);

    $payload = githubPushFlow($repoUrl);
    $this->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret')])
        ->postJson('/webhooks/github', $payload)->assertOk();

    Http::assertNothingSent();
});
