<?php

use App\Models\Organization;
use App\Models\User;

it('offers the organizations the user belongs to', function () {
    $home = Organization::factory()->create(['slug' => 'home', 'name' => 'Home']);
    $other = Organization::factory()->create(['slug' => 'other', 'name' => 'Other']);
    $user = User::factory()->create(['organization_id' => $home->id]);
    $user->organizations()->attach($other->id, ['role' => 'member']);

    $this->actingAs($user)->get('/c/home')
        ->assertInertia(fn ($page) => $page->has('portal.switchable', 2)
            ->where('portal.viewing_as_operator', false));
});

it('tells an operator account whose portal it is looking at', function () {
    Organization::factory()->create(['is_operator' => true]);
    Organization::factory()->create(['slug' => 'acme', 'name' => 'Acme GmbH']);

    $this->actingAs(superAdmin())->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.viewing_as_operator', true)
            ->where('portal.organization.name', 'Acme GmbH'));
});

it('hides the token form from an operator who may not mint', function () {
    Organization::factory()->create(['is_operator' => true]);
    Organization::factory()->create(['slug' => 'acme']);

    // Task 5 refuses the POST. Showing the form anyway would be the shown-and-refused
    // shape this codebase deliberately replaced with hiding on the admin registry page.
    $this->actingAs(superAdmin())->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_mint_tokens', false));
});

it('offers the token form to a member', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('portal.may_mint_tokens', true));
});

it('does not offer an operator the customer list as a switcher', function () {
    Organization::factory()->create(['is_operator' => true]);
    Organization::factory()->create(['slug' => 'acme']);
    Organization::factory()->count(3)->create();

    // The switcher is the viewer's own memberships. Feeding it the customer directory
    // would make that directory a by-product of navigation.
    $this->actingAs(superAdmin())->get('/c/acme')
        ->assertInertia(fn ($page) => $page->has('portal.switchable', 1));
});

it('shares no portal context on a page outside the portal', function () {
    // The ABSENT case for the whole prop. Without it every assertion in this file is
    // consistent with a `portal` key that is simply always there — and the header is a
    // shared prop, so it is rendered on request paths that have no addressed organization
    // at all. `portalOrganization` is set by ResolvePortalContext, which only runs under
    // /c/{orgSlug}; the dashboard is a page a console account really lands on.
    $org = Organization::factory()->create(['slug' => 'acme']);

    $this->actingAs(adminOf($org))->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->where('portal', null));
});

it('leaves an organization whose portal is off out of the switcher', function () {
    // The switcher is navigation, and ResolvePortalContext answers 404 for an organization
    // whose portal is switched off. An entry for one would be a link the viewer can see and
    // cannot follow — the same dead end the /portal redirect used to produce. There is no
    // disclosure question either way: the viewer is a member of both.
    $home = Organization::factory()->create(['slug' => 'home', 'name' => 'Home']);
    $off = Organization::factory()->create(['slug' => 'off', 'name' => 'Off', 'portal_enabled' => false]);
    $user = User::factory()->create(['organization_id' => $home->id]);
    $user->organizations()->attach($off->id, ['role' => 'member']);

    // Whole, not `has(…, 1)`: a count says one row survived, not which one. The reversal —
    // keeping the disabled organization and dropping the addressed one — has the same count.
    $this->actingAs($user)->get('/c/home')
        ->assertInertia(fn ($page) => $page->where('portal.switchable', [
            ['name' => 'Home', 'slug' => 'home'],
        ]));
});
