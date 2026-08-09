<?php

use App\Jobs\SyncPackage;
use App\Models\IncomingWebhook;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

/**
 * A02 — the Bitbucket branch of the incoming-webhook verifier compared the stored secret
 * against `?token=`, i.e. a bare bearer token in the request line. Unlike the github and
 * gitea branches, which HMAC the body and are therefore replay-bound to one payload, this
 * one is disclosed on **every** delivery — into reverse-proxy, CDN and load-balancer
 * access logs, APM traces, Bitbucket's own stored webhook configuration and the operator's
 * shell history — and a single captured URL replays forever, driving SyncPackage for any
 * package matching an attacker-chosen repository URL.
 *
 * It moves to `X-Hub-Signature: sha256=…`, which both Bitbucket Cloud and Data Center send
 * when a webhook secret is configured. That is the same body-bound construction the github
 * branch already uses, so the secret never travels at all.
 *
 * Nothing breaks that worked: the admin UI has never emitted a token-bearing URL
 * (WebhookController builds `/webhooks/{provider}/{hook}`) and the REST docs never
 * described the convention, so a Bitbucket hook configured as documented always 401'd.
 */
function bitbucketPush(string $href = 'https://bitbucket.org/acme/demo'): array
{
    return ['repository' => ['links' => ['html' => ['href' => $href]]]];
}

function bitbucketSignature(array $payload, string $secret): array
{
    return ['X-Hub-Signature' => 'sha256='.hash_hmac('sha256', json_encode($payload), $secret)];
}

it('accepts a bitbucket delivery signed with the hook secret', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://bitbucket.org/acme/demo.git']);
    $hook = IncomingWebhook::create(['name' => 'bb', 'provider' => 'bitbucket', 'enabled' => true, 'secret' => 'whsec_topsecret']);

    $payload = bitbucketPush();

    $this->withHeaders(bitbucketSignature($payload, 'whsec_topsecret'))
        ->postJson("/webhooks/bitbucket/{$hook->id}", $payload)
        ->assertOk()->assertJsonPath('synced', 1);

    Queue::assertPushed(SyncPackage::class);
});

it('refuses a bitbucket delivery that carries the secret in the query string', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://bitbucket.org/acme/demo.git']);
    $hook = IncomingWebhook::create(['name' => 'bb', 'provider' => 'bitbucket', 'enabled' => true, 'secret' => 'whsec_topsecret']);

    // The exact request that used to work. It must not, or the secret keeps travelling
    // in a request line and a captured URL keeps replaying.
    $this->postJson("/webhooks/bitbucket/{$hook->id}?token=whsec_topsecret", bitbucketPush())
        ->assertUnauthorized();

    // Same for the legacy shared endpoint.
    config(['kontorfix.incoming_webhook_secret' => 'topsecret']);
    $this->postJson('/webhooks/bitbucket?token=topsecret', bitbucketPush())->assertUnauthorized();

    Queue::assertNothingPushed();
});

it('refuses a bitbucket delivery signed with the wrong secret or not at all', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://bitbucket.org/acme/demo.git']);
    $hook = IncomingWebhook::create(['name' => 'bb', 'provider' => 'bitbucket', 'enabled' => true, 'secret' => 'whsec_topsecret']);
    $payload = bitbucketPush();

    $this->withHeaders(bitbucketSignature($payload, 'whsec_wrong'))
        ->postJson("/webhooks/bitbucket/{$hook->id}", $payload)->assertUnauthorized();

    // A signature over a *different* body must not authorise this one — the property a
    // bearer token cannot have.
    $this->withHeaders(bitbucketSignature(bitbucketPush('https://bitbucket.org/acme/other'), 'whsec_topsecret'))
        ->postJson("/webhooks/bitbucket/{$hook->id}", $payload)->assertUnauthorized();

    $this->postJson("/webhooks/bitbucket/{$hook->id}", $payload)->assertUnauthorized();

    // The prefix is required, exactly as on the github branch.
    $this->withHeaders(['X-Hub-Signature' => hash_hmac('sha256', json_encode($payload), 'whsec_topsecret')])
        ->postJson("/webhooks/bitbucket/{$hook->id}", $payload)->assertUnauthorized();

    Queue::assertNothingPushed();
});
