<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * The create forms moved off the index pages onto their own `.../create` pages. Every store
 * action that still ended in `back()` therefore returned the operator to the form they had
 * just submitted — a freshly emptied mask that renders no `flash.success`, so the one-request
 * flash was consumed there and thrown away: the create looked like it had done nothing.
 *
 * These tests pin the destination, not just "some redirect": `assertRedirect()` without a
 * target passes for `back()` too, which is exactly why the regression went unnoticed.
 */

// Distinctly named: Pest test files share one global function namespace.
function redirectSuperAdmin(): User
{
    return User::factory()->for(Organization::factory()->create(['is_operator' => true]))->create(['role' => UserRole::Admin]);
}

it('redirects a created package to its detail page carrying the success flash', function () {
    Queue::fake();
    $admin = redirectSuperAdmin();

    $response = $this->actingAs($admin)
        // The real submission comes from the create page; back() would land here.
        ->from(route('admin.packages.create'))
        ->post(route('admin.packages.store'), [
            'group_ids' => [homeRegistryId($admin)],
            'type' => 'composer',
            'name' => 'acme/demo',
            'repository_url' => 'https://git.example.com/acme/demo.git',
        ]);

    $package = Package::where('name', 'acme/demo')->firstOrFail();

    $response->assertSessionHasNoErrors()
        // The detail page, not the index: creation dispatches SyncPackage, and sync status,
        // sync errors and the resync action all live there.
        ->assertRedirect(route('admin.packages.show', $package))
        ->assertSessionHas('success', 'Paket acme/demo angelegt — Sync gestartet.');
});

it('still answers the PackagePicker inline create with json, not a redirect', function () {
    Queue::fake();
    $admin = redirectSuperAdmin();

    // The picker posts with fetch() and Accept: application/json and needs the created row
    // back to add it to its selection — the redirect above must never reach this path.
    $this->actingAs($admin)
        ->postJson(route('admin.packages.store'), [
            'group_ids' => [homeRegistryId($admin)],
            'type' => 'composer',
            'name' => 'acme/inline',
            'repository_url' => 'https://git.example.com/acme/inline.git',
        ])
        ->assertCreated()
        ->assertJson(['name' => 'acme/inline', 'type' => 'composer'])
        ->assertJsonStructure(['id', 'name', 'type']);
});

it('redirects a created oidc provider to the provider index with the flash', function () {
    $this->actingAs(redirectSuperAdmin())
        ->from(route('admin.oidc.create'))
        ->post(route('admin.oidc.store'), [
            'name' => 'Keycloak', 'slug' => 'keycloak', 'client_id' => 'cid', 'client_secret' => 'sec',
            'issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/a',
            'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/j',
            'scopes' => 'openid email profile',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.oidc.index'))
        ->assertSessionHas('success', 'OIDC-Provider erstellt.');
});

it('redirects a created upstream to the upstream index when it came from the create page', function () {
    $admin = redirectSuperAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);

    $this->actingAs($admin)
        ->from(route('admin.upstreams.create'))
        ->post(route('admin.upstreams.store'), [
            'group_id' => $group->id, 'type' => 'composer', 'url' => 'https://packagist.org', 'policy' => 'proxy',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.upstreams.index'))
        ->assertSessionHas('success', 'Upstream erstellt.');
});

it('keeps the inline upstream dialog on the registry detail page', function () {
    $admin = redirectSuperAdmin();
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);
    $registryPage = route('admin.groups.show', $group);

    // The registry detail page posts to the same endpoint from an inline dialog. Sending
    // that operator to the upstreams index would be a worse regression than the bug.
    $this->actingAs($admin)
        ->from($registryPage)
        ->post(route('admin.upstreams.store'), [
            'group_id' => $group->id, 'type' => 'composer', 'url' => 'https://packagist.org', 'policy' => 'proxy',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect($registryPage);
});

it('redirects a created user to the user index with the flash', function () {
    $admin = redirectSuperAdmin();
    $org = Organization::factory()->create();

    $this->actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), [
            'name' => 'Neu', 'email' => 'neu@kunde.test', 'organization_id' => $org->id,
            'role' => 'member', 'password' => 'geheim-1234',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'Nutzer Neu angelegt.');
});

it('redirects an invited user to the user index with the flash', function () {
    $admin = redirectSuperAdmin();
    $org = Organization::factory()->create();

    // No password submitted: the second of the two returns in UserController::store.
    $this->actingAs($admin)
        ->from(route('admin.users.create'))
        ->post(route('admin.users.store'), [
            'name' => 'Gast', 'email' => 'gast@kunde.test', 'organization_id' => $org->id, 'role' => 'member',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.index'))
        ->assertSessionHas('success', 'Nutzer Gast eingeladen.');
});

it('redirects a created outgoing webhook to the webhook index with the flash', function () {
    $this->actingAs(redirectSuperAdmin())
        ->from(route('admin.webhooks.create'))
        ->post(route('admin.webhooks.store'), [
            'url' => 'https://hooks.example.com/kfx',
            'events' => ['package.synced'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.webhooks.index'))
        ->assertSessionHas('success', 'Webhook erstellt.');
});

it('redirects a created git credential to the credential index with the flash', function () {
    $admin = redirectSuperAdmin();

    $this->actingAs($admin)
        ->from(route('admin.git-credentials.create'))
        ->post(route('admin.git-credentials.store'), [
            'name' => 'GH Deploy', 'organization_id' => $admin->organization_id, 'provider' => 'github', 'token' => 'ghp_secret',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.git-credentials.index'))
        ->assertSessionHas('success', 'Git-Token gespeichert.');
});

it('redirects a created notification recipient to the recipient index with the flash', function () {
    $this->actingAs(redirectSuperAdmin())
        ->from(route('admin.notification-recipients.create'))
        ->post(route('admin.notification-recipients.store'), [
            'email' => 'ops@example.test',
            'name' => 'Ops Team',
            'events' => ['sync.failed'],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.notification-recipients.index'))
        ->assertSessionHas('success', 'Empfänger erstellt.');
});
