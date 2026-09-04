<?php

/*
 * The portal's three registry pages, on the organization the URL addresses.
 *
 * Two changes meet here. The first: `index()` listed every registry of every organization
 * the viewer belongs to, which was the only answer available before `/c/{orgSlug}` existed.
 * The URL now names one organization, and a page that merges a second one into it makes the
 * address a lie — and makes an operator looking at a customer's portal see their own
 * registries listed inside it.
 *
 * The second: PortalLapsedAssignmentTest made these pages HIDE an assignment past its
 * `available_until`, because at the time the portal had no way to say anything about one.
 * Task 3 gave it one (`badgesFor`, `lapsedNote`), so hiding is no longer the best available
 * answer — it is the worst one. The customer whose build answers 404 arrived at a portal that
 * did not list the package at all, which reads as "it was never there" rather than as "it
 * lapsed". The row is shown, marked, and the detail page explains itself instead of 404ing.
 *
 * Every payload flag below is asserted in BOTH directions. `in_force` sent as a constant
 * `false` would satisfy the lapsed cases on its own, and `shared` likewise.
 */

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

it('lists only the addressed organization registries', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    Group::factory()->for($org)->create(['name' => 'Meine']);
    $otherOrg = Organization::factory()->create();
    Group::factory()->for($otherOrg)->create(['name' => 'Fremde']);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $user->organizations()->attach($otherOrg->id, ['role' => 'member']);

    // The user belongs to both, but the URL names one — /portal used to merge them.
    $this->actingAs($user)->get('/c/acme/registries')
        ->assertInertia(fn ($page) => $page->has('registries', 1)
            ->where('registries.0.name', 'Meine'));
});

it('lists the same users other organization registries at that organizations own address', function () {
    // The reversal of the case above, and the reason it is a scoping change rather than a
    // narrowing: the second organization's registries did not become unreachable, they moved
    // to the address that names them. A fix that simply dropped the extra memberships would
    // pass the test above and fail this one.
    $org = Organization::factory()->create(['slug' => 'acme']);
    Group::factory()->for($org)->create(['name' => 'Meine']);
    $otherOrg = Organization::factory()->create(['slug' => 'other']);
    Group::factory()->for($otherOrg)->create(['name' => 'Fremde']);
    $user = User::factory()->create(['organization_id' => $org->id]);
    $user->organizations()->attach($otherOrg->id, ['role' => 'member']);

    $this->actingAs($user)->get('/c/other/registries')
        ->assertInertia(fn ($page) => $page->has('registries', 1)
            ->where('registries.0.name', 'Fremde'));
});

it('omits a registry whose own portal switch is off', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    Group::factory()->for($org)->create(['name' => 'Versteckt', 'portal_enabled' => false]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme/registries')
        ->assertInertia(fn ($page) => $page->has('registries', 0));
});

it('lists a registry whose own portal switch is on', function () {
    // The present half of the case above. Deliberately its OWN organization with a single
    // registry rather than a second row in that test: a page that ignored the switch would
    // still pass this one, which is what makes the pair diagnostic — removing the filter
    // reddens the omission test alone.
    $org = Organization::factory()->create(['slug' => 'acme']);
    Group::factory()->for($org)->create(['name' => 'Sichtbar', 'portal_enabled' => true]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme/registries')
        ->assertInertia(fn ($page) => $page->has('registries', 1)
            ->where('registries.0.name', 'Sichtbar'));
});

it('shows a lapsed package on the registry page, marked, instead of hiding it', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'main']);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $group->packages()->attach($shared->id, ['available_until' => now()->subDay()]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertInertia(fn ($page) => $page->where('packages.0.in_force', false));
});

it('leaves a package the registry still serves in force on the registry page', function () {
    // The reversal. `in_force` sent as a constant false satisfies the case above, and a page
    // that marked every row would tell every customer their builds are broken.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'main']);
    $own = Package::factory()->inOrgOf($group)->create(['name' => 'acme/live']);
    $group->packages()->attach($own->id);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertInertia(fn ($page) => $page->where('packages.0.in_force', true)
            // `shared` travels with the row for the same badge Packages.vue renders, and a
            // customer's own package is the absent case for it.
            ->where('packages.0.shared', false)
            ->etc());
});

it('marks a shared package on the registry page as shared', function () {
    // The present half of `shared`. A row that omitted the field would arrive as null and
    // render no badge at all, which is the failure mode a false-only assertion cannot see.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create(['slug' => 'main']);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $group->packages()->attach($shared->id);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")
        ->assertInertia(fn ($page) => $page->where('packages.0.shared', true)->etc());
});

it('serves the package detail page for a lapsed assignment with the explanation', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $group->packages()->attach($shared->id, ['available_until' => now()->subDay()]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    // 404 was the old answer. The customer needs the page to say why the build fails.
    $this->actingAs($user)->get("/c/acme/registries/{$group->id}/packages/{$shared->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('in_force', false));
});

it('serves the package detail page for a live assignment as in force', function () {
    // The reversal, on the page whose whole layout branches on this flag: `in_force` false
    // replaces the install snippet, so a constant false would hide the command from every
    // customer for every package.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $own = Package::factory()->inOrgOf($group)->create(['name' => 'acme/live']);
    $group->packages()->attach($own->id);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}/packages/{$own->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('in_force', true)->etc());
});

it('still refuses a package that is not assigned to this registry at all', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $loose = Package::factory()->for($org)->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}/packages/{$loose->id}")
        ->assertNotFound();
});

/*
 * The two definitions of "operator account", which used to disagree.
 *
 * ResolvePortalContext::mayOpen() admits anyone who administers an operator organization —
 * admin OR maintainer, by home role OR by an organization membership's pivot role.
 * GroupPolicy::view() asked a narrower question (home role Admin at an is_operator home
 * organization), so the accounts in the gap were let into a customer portal and then answered
 * 403 on every registry inside it. Spec decision 4 — an operator sees what the customer sees —
 * is not met while a portal has pages its own gate refuses.
 *
 * The `is_super_admin` case is deliberately absent: AppServiceProvider's `Gate::before`
 * short-circuits every policy for a super-admin, so that population never reached the narrow
 * check. Verified by running it before touching the policy.
 */
it('lets a maintainer of the operator organization open a registry inside a customer portal', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();

    $this->actingAs(operatorMaintainer())->get("/c/acme/registries/{$group->id}")->assertOk();
});

it('lets an operator admin by membership open a registry inside a customer portal', function () {
    // The pivot shape. The account's HOME organization is an ordinary customer, so every
    // check that reads `$user->organization` answers no — the role lives on the membership.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $user = User::factory()->for(Organization::factory()->create())->create(['role' => UserRole::Member]);
    $user->organizations()->attach($operator->id, ['role' => 'admin']);

    $this->actingAs($user)->get("/c/acme/registries/{$group->id}")->assertOk();
});

it('still refuses a maintainer of an ordinary customer organization', function () {
    // The reversal, and the reason the widening is about the operator flag and not about the
    // Maintainer role: a maintainer whose organization is not the operator's has no more
    // reach into a foreign customer than a member does.
    Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();

    $this->actingAs(maintainerOf(Organization::factory()->create()))
        ->get("/c/acme/registries/{$group->id}")
        ->assertNotFound();
});
