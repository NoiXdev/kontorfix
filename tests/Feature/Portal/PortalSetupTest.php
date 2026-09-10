<?php

/*
 * Task 7: the portal's organization-wide "Einrichtung" tab. `portal.setup` serves the
 * `forOrganization()` snippet set (Task 6) alongside the caller's OWN org-wide tokens — the
 * same "personal tokens on this surface" rule RegistryController::show() already applies to
 * a single registry's token list, scoped here to `group_id IS NULL` instead of one group.
 *
 * The route sits behind the same `portal.context` middleware as every other portal page, so
 * the non-member and portal-disabled 404s are ResolvePortalContext's own behavior — smoke
 * tests here, not a second implementation of that gate.
 */

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\User;

it('serves the organization-wide snippet set and types to a member', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    Group::factory()->for($org)->create(['slug' => 'main']);
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    $this->actingAs($user)->get(route('portal.setup', $org->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('portal/Setup')
            ->where('orgSlug', 'acme')
            // The org-wide builder, not a per-group one — see SetupSnippetBuilderTest for
            // what it contains. Asserted here only as "the org path is in it", which a
            // per-group for() call could never produce (it addresses /r/, not /o/).
            ->where('snippets.composer', fn ($v) => str_contains($v, '/o/acme'))
            ->has('types')
            ->has('can_publish')
            ->etc());
});

it('lists only the callers own org-wide tokens, not a group-bound one', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    // Org-wide, owned by the viewer: the one row the page must show.
    RegistryToken::issue($org, 'ci-org-wide', null, owner: $user);
    // Group-bound, owned by the same viewer: must be ABSENT — this tab is about the
    // org-wide credential, and RegistryController::show() already lists this one.
    RegistryToken::issue($org, 'ci-group-bound', $group, owner: $user);
    // Org-wide, owned by a different member: must be absent too, same "own tokens only"
    // rule PortalTokenIsolationTest already pins for the per-group list.
    $other = User::factory()->for($org)->create(['role' => UserRole::Member]);
    RegistryToken::issue($org, 'someone-elses', null, owner: $other);

    $this->actingAs($user)->get(route('portal.setup', $org->slug))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tokens', 1)
            ->where('tokens.0.name', 'ci-org-wide')
            ->etc());
});

it('404s for a non-member', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(route('portal.setup', $org->slug))->assertNotFound();
});

it('404s when the organizations portal is switched off', function () {
    $org = Organization::factory()->create(['slug' => 'acme', 'portal_enabled' => false]);
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);

    $this->actingAs($user)->get(route('portal.setup', $org->slug))->assertNotFound();
});

it('reports whether the viewer may mint a publish token', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);

    $this->actingAs($member)->get(route('portal.setup', $org->slug))
        ->assertInertia(fn ($page) => $page->where('can_publish', false));

    $this->actingAs($admin)->get(route('portal.setup', $org->slug))
        ->assertInertia(fn ($page) => $page->where('can_publish', true));
});

it('still refuses a plain member minting a publish-ability org-wide token', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $member = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $this->withSession(['auth.password_confirmed_at' => time()]);

    // No group_id at all — the Einrichtung tab's mint form never sends one — and the
    // publish gate (RegistryTokenPolicy::create) must still refuse it. TokenController::
    // store() authorizes this ahead of RegistryToken::issue(), so a bypass here would
    // write the row before answering the refusal.
    $this->actingAs($member)
        ->post(route('portal.tokens.store', $org->slug), ['name' => 'ci', 'ability' => 'publish'])
        ->assertForbidden();

    expect(RegistryToken::count())->toBe(0);
});

/*
 * The Einrichtung tab's own token list needs a revoke path — `portal.tokens.destroy`
 * already exists (RegistryController::show()'s per-registry token list uses it) and
 * TokenController::destroy() + RegistryTokenPolicy::delete() do not read `group_id` at
 * all: the policy branches on organization membership plus either token ownership
 * (personal) or `administers()` (org-shared, ownerless), both of which are exactly as
 * meaningful for a `group_id IS NULL` row as for a group-bound one. These cases pin that
 * down directly, rather than trusting the per-group tests to generalize to it.
 */
it('lets the owner revoke their own org-wide token, and it leaves the setup tabs list', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);
    [$token] = RegistryToken::issue($org, 'ci-org-wide', null, owner: $user);

    $this->actingAs($user)
        ->delete(route('portal.tokens.destroy', [$org->slug, $token->id]))
        ->assertRedirect();

    expect(RegistryToken::find($token->id))->toBeNull();

    $this->actingAs($user)->get(route('portal.setup', $org->slug))
        ->assertInertia(fn ($page) => $page->has('tokens', 0));
});

it('refuses a member revoking another members org-wide token', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $owner = User::factory()->for($org)->create(['role' => UserRole::Member]);
    $other = User::factory()->for($org)->create(['role' => UserRole::Member]);
    [$token] = RegistryToken::issue($org, 'ci-org-wide', null, owner: $owner);

    $this->actingAs($other)
        ->delete(route('portal.tokens.destroy', [$org->slug, $token->id]))
        ->assertForbidden();

    // The status alone would not distinguish a refusal from one that still deleted the row.
    expect(RegistryToken::find($token->id))->not->toBeNull();
});

it('refuses revoking an org-wide token of another organization through this portals address', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $other = Organization::factory()->create();
    $admin = User::factory()->for($org)->create(['role' => UserRole::Admin]);
    $admin->organizations()->attach($other->id, ['role' => 'admin']);
    [$foreignToken] = RegistryToken::issue($other, 'foreign-org-wide', null, owner: $admin);

    // TokenController::destroy() binds the token to the organization the URL names, ahead
    // of the policy — the same rule PortalTokenIsolationTest already pins for a group-bound
    // token, exercised here against one with `group_id === null`.
    $this->actingAs($admin)
        ->delete(route('portal.tokens.destroy', [$org->slug, $foreignToken->id]))
        ->assertForbidden();

    expect(RegistryToken::find($foreignToken->id))->not->toBeNull();
});

it('still excludes a group-bound token from the setup tabs list after an org-wide one is revoked', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $user = User::factory()->for($org)->create(['role' => UserRole::Member]);
    [$orgWide] = RegistryToken::issue($org, 'ci-org-wide', null, owner: $user);
    RegistryToken::issue($org, 'ci-group-bound', $group, owner: $user);

    $this->actingAs($user)->delete(route('portal.tokens.destroy', [$org->slug, $orgWide->id]))->assertRedirect();

    $this->actingAs($user)->get(route('portal.setup', $org->slug))
        ->assertInertia(fn ($page) => $page->has('tokens', 0));
});
