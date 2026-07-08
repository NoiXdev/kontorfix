<?php

use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => config(['kontorfix.incoming_webhook_secret' => 'topsecret']));

function githubPush(string $cloneUrl): array
{
    return ['repository' => ['clone_url' => $cloneUrl]];
}
function githubSig(array $payload): string
{
    return 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret');
}

it('resyncs matching packages on a valid github push', function () {
    Queue::fake();
    $pkg = Package::factory()->create(['repository_url' => 'https://github.com/acme/demo.git']);
    $payload = githubPush('https://github.com/acme/demo.git');

    $this->withHeaders(['X-Hub-Signature-256' => githubSig($payload)])
        ->postJson('/webhooks/github', $payload)->assertOk()->assertJsonPath('synced', 1);

    Queue::assertPushed(SyncPackage::class, fn ($job) => $job->package->is($pkg));
});

it('rejects a github push with a bad signature', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://github.com/acme/demo.git']);
    $payload = githubPush('https://github.com/acme/demo.git');

    $this->withHeaders(['X-Hub-Signature-256' => 'sha256=deadbeef'])
        ->postJson('/webhooks/github', $payload)->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('rejects a github push with no signature', function () {
    Queue::fake();
    $this->postJson('/webhooks/github', githubPush('https://github.com/acme/demo.git'))->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('accepts a valid gitlab token push', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://gitlab.com/acme/demo.git']);

    $this->withHeaders(['X-Gitlab-Token' => 'topsecret'])
        ->postJson('/webhooks/gitlab', ['repository' => ['git_http_url' => 'https://gitlab.com/acme/demo.git']])
        ->assertOk()->assertJsonPath('synced', 1);
    Queue::assertPushed(SyncPackage::class);
});

it('returns synced=0 when no package matches (valid signature)', function () {
    Queue::fake();
    $payload = githubPush('https://github.com/unknown/repo.git');
    $this->withHeaders(['X-Hub-Signature-256' => githubSig($payload)])
        ->postJson('/webhooks/github', $payload)->assertOk()->assertJsonPath('synced', 0);
    Queue::assertNothingPushed();
});

it('404s an unknown provider', function () {
    $this->postJson('/webhooks/svn', [])->assertNotFound();
});

it('503 when no incoming secret is configured', function () {
    config(['kontorfix.incoming_webhook_secret' => null]);
    $this->postJson('/webhooks/github', githubPush('https://github.com/acme/demo.git'))->assertStatus(503);
});

it('accepts a valid gitea signature', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://gitea.example.com/acme/demo.git']);
    $payload = ['repository' => ['clone_url' => 'https://gitea.example.com/acme/demo.git']];
    $sig = hash_hmac('sha256', json_encode($payload), 'topsecret'); // Gitea: hex, kein Prefix

    $this->withHeaders(['X-Gitea-Signature' => $sig])
        ->postJson('/webhooks/gitea', $payload)->assertOk()->assertJsonPath('synced', 1);
    Queue::assertPushed(SyncPackage::class);
});

it('rejects a gitea push with a bad signature', function () {
    Queue::fake();
    Package::factory()->create(['repository_url' => 'https://gitea.example.com/acme/demo.git']);
    $this->withHeaders(['X-Gitea-Signature' => 'deadbeef'])
        ->postJson('/webhooks/gitea', ['repository' => ['clone_url' => 'https://gitea.example.com/acme/demo.git']])
        ->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('accepts a valid bitbucket token and matches the html url against a clone url', function () {
    Queue::fake();
    // Paket ist mit der .git-Clone-URL registriert; Bitbucket liefert die html-URL.
    Package::factory()->create(['repository_url' => 'https://bitbucket.org/acme/demo.git']);

    $this->postJson('/webhooks/bitbucket?token=topsecret', [
        'repository' => ['links' => ['html' => ['href' => 'https://bitbucket.org/acme/demo']]],
    ])->assertOk()->assertJsonPath('synced', 1);
    Queue::assertPushed(SyncPackage::class);
});

it('rejects a bitbucket push with a bad token', function () {
    Queue::fake();
    $this->postJson('/webhooks/bitbucket?token=wrong', [
        'repository' => ['links' => ['html' => ['href' => 'https://bitbucket.org/acme/demo']]],
    ])->assertUnauthorized();
    Queue::assertNothingPushed();
});

it('returns 422 for a valid signature but unparseable payload', function () {
    Queue::fake();
    $payload = ['not' => 'a repository'];
    $this->withHeaders(['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', json_encode($payload), 'topsecret')])
        ->postJson('/webhooks/github', $payload)->assertStatus(422);
    Queue::assertNothingPushed();
});
