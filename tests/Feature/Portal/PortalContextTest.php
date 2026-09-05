<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

it('opens the portal for a member of the organization', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')->assertOk();
});

it('answers 404 for an organization the viewer does not belong to', function () {
    Organization::factory()->create(['slug' => 'acme']);
    $other = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $other->id]);

    $this->actingAs($user)->get('/c/acme')->assertNotFound();
});

it('answers the same 404 for an organization that does not exist', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['organization_id' => $org->id]);

    // The two responses must be indistinguishable: a 403 on the case above would
    // confirm that customer exists.
    $this->actingAs($user)->get('/c/no-such-customer')->assertNotFound();
});

it('answers 404 when the portal is switched off', function () {
    $org = Organization::factory()->create(['slug' => 'acme', 'portal_enabled' => false]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')->assertNotFound();
});

it('keeps serving the registries of an organization whose portal is off', function () {
    $org = Organization::factory()->create(['slug' => 'acme', 'portal_enabled' => false]);
    // `public` so the assertion is about the portal switch and nothing else: a private
    // registry answers 401 to an anonymous request whatever the organization's portal does.
    $group = Group::factory()->for($org)->create(['slug' => 'main', 'public' => true]);
    $npm = Package::factory()->inOrgOf($group)->create(['name' => 'acme-widget', 'type' => PackageType::Npm]);
    PackageVersion::factory()->for($npm)->create();
    $group->packages()->attach($npm);

    // The registry endpoints are a different surface; a disabled portal breaks no build —
    // and "no build" means all three ecosystems, not only the one that happens to have a
    // root document. A guard placed one layer too low would break exactly one of these.
    $this->get('/r/acme/main/packages.json')->assertOk();
    $this->get('/r/acme/main/acme-widget')->assertOk();
    $this->get('/r/acme/main/simple')->assertOk();
});

/**
 * The other switch, the same guarantee. `groups.portal_enabled` decides whether a registry
 * appears in the portal and nothing else — a registry hidden from the portal keeps serving
 * every ecosystem exactly as before, so hiding a collection-only registry cannot break a
 * build that resolves against it. The sibling above says the same for the organization-level
 * switch; documented in docs/development.md, and until now true only by the absence of a
 * check rather than by anything that would redden if one were added.
 */
it('keeps serving a registry that is hidden from the portal', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    // `public` for the same reason as above: the assertion is about the portal switch alone.
    $group = Group::factory()->for($org)->create(['slug' => 'main', 'public' => true, 'portal_enabled' => false]);
    $npm = Package::factory()->inOrgOf($group)->create(['name' => 'acme-widget', 'type' => PackageType::Npm]);
    PackageVersion::factory()->for($npm)->create();
    $group->packages()->attach($npm);

    $this->get('/r/acme/main/packages.json')->assertOk();
    $this->get('/r/acme/main/acme-widget')->assertOk();
    $this->get('/r/acme/main/simple')->assertOk();
});

it('lets an operator account open a customer portal', function () {
    // superAdmin() brings the operator organization with it; a second one would put the
    // instance in a state SetupController never produces.
    Organization::factory()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->get('/c/acme')->assertOk();
});

it('lets a super-admin in on an instance that has no operator organization', function () {
    // The operator-organization scan below the super-admin clause can only answer yes if an
    // `is_operator` row exists. SetupController always creates one, but nothing in the rule
    // depends on it: the flag is what makes this account a super-admin. Without the clause
    // in front, such an instance 404s its own super-admin on every customer portal.
    Organization::factory()->create(['slug' => 'acme']);
    $super = User::factory()->for(Organization::factory()->create())->create(['is_super_admin' => true]);

    $this->actingAs($super)->get('/c/acme')->assertOk();
});

it('answers 404 to an admin of one customer looking at another customer portal', function () {
    // The non-member case above uses a plain member. Privilege inside one's own
    // organization is not reach into somebody else's, and an org admin is the account that
    // would most plausibly be let through by an over-broad rule.
    Organization::factory()->create(['slug' => 'acme']);
    $other = Organization::factory()->create();

    $this->actingAs(adminOf($other))->get('/c/acme')->assertNotFound();
});

it('redirects the old portal path to the home organization', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/portal')->assertRedirect('/c/acme');
});

it('shows a member of an organization whose portal is off a page instead of a dead redirect', function () {
    // The dead end the "404, never 403" rule leaves behind, and the operator creates it by
    // using the feature: /dashboard sends a plain member to /portal, /portal redirected them
    // to /c/{slug}, and the gate answered 404 there. Nothing this account can reach.
    //
    // No disclosure question here, unlike at the gate: the viewer is a member and already
    // knows the organization exists. So it is told, rather than sent to a 404.
    $org = Organization::factory()->create(['slug' => 'acme', 'name' => 'Acme GmbH', 'portal_enabled' => false]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/PortalDisabled')
            ->where('organization', 'Acme GmbH'));
});

it('sends a member of an organization whose portal is off from the dashboard to that same page', function () {
    // Its own test rather than a second assertion above: the dashboard reaches this through
    // DashboardController delegating to PackageController::home(), which is a different call
    // path, and a failure on the /portal assertion would hide whether this one still works.
    $org = Organization::factory()->create(['slug' => 'acme', 'name' => 'Acme GmbH', 'portal_enabled' => false]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/PortalDisabled')
            ->where('organization', 'Acme GmbH'));
});

it('defaults an organization to having a portal', function () {
    expect(Organization::factory()->create()->portal_enabled)->toBeTrue();
});

it('switches a customer portal off from the console', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    // The payload the console actually sends: Inertia's useForm serialises every field it
    // holds, so an unchecked switch arrives as a present `false` rather than as an absent
    // key. The absent key is a different intention entirely — see the case below.
    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org->id), [
        'name' => $org->name,
        'slug' => $org->slug,
        'notification_cadence' => 'hourly',
        'portal_enabled' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($org->fresh()->portal_enabled)->toBeFalse();
});

it('leaves the portal switch alone when the request does not mention it', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    // Not the console's payload — a request that never names the switch. "Off" and "not
    // mentioned" used to be the same thing here, so renaming a customer disabled their
    // portal as a side effect. (`notification_cadence` is `required`, so even a minimal
    // update carries it; the switch is the only field whose absence is interesting.)
    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org->id), [
        'name' => 'Umbenannt',
        'slug' => $org->slug,
        'notification_cadence' => 'hourly',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($org->fresh())
        ->name->toBe('Umbenannt')
        ->portal_enabled->toBeTrue();
});

it('switches a customer portal back on from the console', function () {
    // Asserted in both directions: a save that hard-coded either answer would satisfy the
    // case above, and the operator has to be able to undo the switch as well as set it.
    $org = Organization::factory()->create(['slug' => 'acme', 'portal_enabled' => false]);

    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org->id), [
        'name' => $org->name,
        'slug' => $org->slug,
        'notification_cadence' => 'hourly',
        'portal_enabled' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($org->fresh()->portal_enabled)->toBeTrue();
});

it('shows an account with no home organization a page instead of a fatal', function () {
    // users.organization_id is nullable and RegisteredUserController::store() creates a
    // self-registered account without one, which then lands on /dashboard. Reading ->slug
    // off that null was a 500 on the first page such an account ever sees.
    $user = User::factory()->create(['organization_id' => null]);

    $this->actingAs($user)->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/NoOrganization'));
});

it('sends an account with no home organization from the dashboard to that same page', function () {
    $user = User::factory()->create(['organization_id' => null]);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('portal/NoOrganization'));
});
