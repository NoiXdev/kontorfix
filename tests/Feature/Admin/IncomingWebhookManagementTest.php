<?php

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\IncomingWebhook;
use App\Models\IncomingWebhookEvent;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

function webhookAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

/** GitHub-style signed request against a given secret. */
function githubSigned(string $secret, array $payload): array
{
    $body = json_encode($payload);

    return [
        'headers' => ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, $secret)],
        'body' => $body,
    ];
}

it('creates an incoming webhook and reveals the secret once', function () {
    $this->actingAs(webhookAdmin())->post('/admin/incoming-webhooks', [
        'name' => 'GitHub acme',
        'provider' => 'github',
    ])->assertRedirect()->assertSessionHas('incomingWebhookSecret')->assertSessionHas('incomingWebhookUrl');

    $hook = IncomingWebhook::sole();
    expect($hook->provider)->toBe('github');
    expect($hook->secret)->toStartWith('whsec_');
});

it('stores the incoming secret encrypted', function () {
    $this->actingAs(webhookAdmin())->post('/admin/incoming-webhooks', ['name' => 'x', 'provider' => 'github']);

    $raw = DB::table('incoming_webhooks')->value('secret');
    expect($raw)->not->toStartWith('whsec_');
});

it('regenerates and deletes an incoming webhook', function () {
    $admin = webhookAdmin();
    $hook = IncomingWebhook::create(['name' => 'x', 'provider' => 'github', 'secret' => 'whsec_old']);

    $this->actingAs($admin)->post("/admin/incoming-webhooks/{$hook->id}/regenerate")
        ->assertRedirect()->assertSessionHas('incomingWebhookSecret');
    expect($hook->fresh()->secret)->not->toBe('whsec_old');

    $this->actingAs($admin)->delete("/admin/incoming-webhooks/{$hook->id}")->assertRedirect();
    expect(IncomingWebhook::count())->toBe(0);
});

it('accepts a valid signed delivery on the per-record endpoint and records the event', function () {
    Queue::fake();
    $hook = IncomingWebhook::create(['name' => 'gh', 'provider' => 'github', 'secret' => 'sekrit']);
    Package::factory()->create(['type' => PackageType::Composer, 'repository_url' => 'https://github.com/acme/tools']);

    $signed = githubSigned('sekrit', ['repository' => ['clone_url' => 'https://github.com/acme/tools.git']]);

    $this->call('POST', "/webhooks/github/{$hook->id}", [], [], [], $this->transformHeadersToServerVars($signed['headers'] + ['Content-Type' => 'application/json']), $signed['body'])
        ->assertOk()->assertJson(['synced' => 1]);

    $event = IncomingWebhookEvent::sole();
    expect($event->signature_valid)->toBeTrue();
    expect($event->matched_packages)->toBe(1);
    expect($event->incoming_webhook_id)->toBe($hook->id);
    expect($hook->fresh()->last_received_at)->not->toBeNull();
});

it('records a failed verification with the payload for debugging', function () {
    $hook = IncomingWebhook::create(['name' => 'gh', 'provider' => 'github', 'secret' => 'sekrit']);

    $body = json_encode(['repository' => ['clone_url' => 'https://github.com/acme/tools.git']]);
    $this->call('POST', "/webhooks/github/{$hook->id}", [], [], [], $this->transformHeadersToServerVars([
        'X-Hub-Signature-256' => 'sha256=wrong',
        'Content-Type' => 'application/json',
    ]), $body)->assertUnauthorized();

    $event = IncomingWebhookEvent::sole();
    expect($event->signature_valid)->toBeFalse();
    expect($event->status_code)->toBe(401);
    expect($event->payload)->toHaveKey('repository');
});

it('404s a per-record endpoint when the provider does not match', function () {
    $hook = IncomingWebhook::create(['name' => 'gl', 'provider' => 'gitlab', 'secret' => 's']);

    $this->postJson("/webhooks/github/{$hook->id}", [])->assertNotFound();
});

it('caps the incoming audit at the retention limit', function () {
    // One over the limit; the oldest must be pruned on write.
    foreach (range(1, IncomingWebhookEvent::RETENTION + 1) as $i) {
        IncomingWebhookEvent::record(['provider' => 'github', 'signature_valid' => true, 'status_code' => 200]);
    }

    expect(IncomingWebhookEvent::count())->toBe(IncomingWebhookEvent::RETENTION);
});

it('shows the webhook audit to an operator admin', function () {
    IncomingWebhookEvent::record(['provider' => 'github', 'signature_valid' => true, 'status_code' => 200, 'payload' => ['a' => 1]]);

    $this->actingAs(webhookAdmin())->get('/admin/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/webhooks/Index')
            ->has('incoming')
            ->has('audit.incoming', 1)
            ->has('audit.outgoing'));
});

it('denies incoming webhook management to non-operators', function () {
    $member = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Member]);

    $this->actingAs($member)->post('/admin/incoming-webhooks', ['name' => 'x', 'provider' => 'github'])->assertForbidden();
});
