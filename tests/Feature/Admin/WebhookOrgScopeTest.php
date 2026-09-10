<?php

use App\Enums\UserRole;
use App\Enums\WebhookEvent;
use App\Models\Organization;
use App\Models\User;
use App\Models\Webhook;

// An operator-org admin is isSuperAdmin() (grandfathered), so — like a literal super-admin —
// it administers every organization and may pick any of them as the active console scope.
function webhookScopeOperatorAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('lists only the active scope organization\'s webhooks, deliveries and audit rows', function () {
    $admin = webhookScopeOperatorAdmin();
    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);

    $whA = Webhook::factory()->create(['organization_id' => $orgA->id, 'url' => 'https://a.example.test/hook']);
    $whB = Webhook::factory()->create(['organization_id' => $orgB->id, 'url' => 'https://b.example.test/hook']);
    $whA->deliveries()->create(['event' => WebhookEvent::PackageSynced->value, 'payload' => [], 'status_code' => 200, 'success' => true, 'attempts' => 1, 'delivered_at' => now()]);
    $whB->deliveries()->create(['event' => WebhookEvent::PackageSynced->value, 'payload' => [], 'status_code' => 500, 'success' => false, 'attempts' => 1, 'delivered_at' => now()]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $orgA->id])
        ->get('/admin/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('webhooks', 1)
            ->where('webhooks.0.url', 'https://a.example.test/hook')
            ->where('webhooks.0.organization', 'Org A')
            ->has('audit.outgoing', 1)
            ->where('audit.outgoing.0.url', 'https://a.example.test/hook'));
});

it('shows every organization\'s webhooks, including legacy null-org ones, when unscoped', function () {
    $admin = webhookScopeOperatorAdmin();
    $orgA = Organization::factory()->create();
    $orgB = Organization::factory()->create();

    Webhook::factory()->create(['organization_id' => $orgA->id]);
    Webhook::factory()->create(['organization_id' => $orgB->id]);
    Webhook::factory()->create(['organization_id' => null, 'url' => 'https://legacy.example.test/hook']);

    $this->actingAs($admin)
        ->get('/admin/webhooks')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('webhooks', 3)
            ->where('webhooks.2.organization', null));
});

it('attributes a new webhook to the active scope organization, not the caller\'s own', function () {
    $admin = webhookScopeOperatorAdmin();
    $otherOrg = Organization::factory()->create();

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $otherOrg->id])
        ->post('/admin/webhooks', [
            'url' => 'https://scoped.example.test/hook',
            'events' => ['package.synced'],
        ])->assertRedirect();

    $webhook = Webhook::where('url', 'https://scoped.example.test/hook')->firstOrFail();
    expect($webhook->organization_id)->toBe($otherOrg->id)
        ->and($webhook->organization_id)->not->toBe($admin->organization_id);
});

it('refuses to delete a webhook belonging to an organization outside the active scope', function () {
    $admin = webhookScopeOperatorAdmin();
    $ownOrg = Organization::factory()->create();
    $foreignOrg = Organization::factory()->create();
    $foreignWebhook = Webhook::factory()->create(['organization_id' => $foreignOrg->id]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/webhooks/{$foreignWebhook->id}")
        ->assertForbidden();

    expect(Webhook::find($foreignWebhook->id))->not->toBeNull();
});

it('refuses to delete a legacy null-org webhook while scoped to a specific organization', function () {
    $admin = webhookScopeOperatorAdmin();
    $ownOrg = Organization::factory()->create();
    $legacy = Webhook::factory()->create(['organization_id' => null]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $ownOrg->id])
        ->delete("/admin/webhooks/{$legacy->id}")
        ->assertForbidden();

    expect(Webhook::find($legacy->id))->not->toBeNull();
});

it('allows deleting a webhook that belongs to the active scope organization', function () {
    $admin = webhookScopeOperatorAdmin();
    $org = Organization::factory()->create();
    $webhook = Webhook::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($admin)
        ->withSession(['admin.scope_org_id' => $org->id])
        ->delete("/admin/webhooks/{$webhook->id}")
        ->assertRedirect();

    expect(Webhook::find($webhook->id))->toBeNull();
});
