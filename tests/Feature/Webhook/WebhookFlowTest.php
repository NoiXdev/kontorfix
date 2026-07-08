<?php

use App\Enums\WebhookEvent;
use App\Models\Package;
use App\Models\Webhook;
use Illuminate\Support\Facades\Http;
use Tests\Support\FixtureRepo;

beforeEach(function () {
    config(['kontorfix.incoming_webhook_secret' => 'topsecret']);
    // Sync-Queue: der Incoming-Request führt SyncPackage (und die ausgehende Zustellung)
    // synchron aus, sodass der komplette Flow in einem Request durchläuft.
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

    // Das Paket wurde tatsächlich synchronisiert (Versionen aus dem Fixture-Repo).
    expect($pkg->fresh()->versions()->count())->toBeGreaterThan(0);

    // Und eine signierte Zustellung an den abonnierten Webhook ist erfolgt + geloggt.
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
    // Der Sync scheitert (Repo existiert nicht) und wird nach den Retries endgültig fehlschlagen;
    // der Incoming-Request selbst bleibt erfolgreich (er reiht nur den Job ein).
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
