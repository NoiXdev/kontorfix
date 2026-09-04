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

it('lets an operator account open a customer portal', function () {
    // superAdmin() brings the operator organization with it; a second one would put the
    // instance in a state SetupController never produces.
    Organization::factory()->create(['slug' => 'acme']);

    $this->actingAs(superAdmin())->get('/c/acme')->assertOk();
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

it('defaults an organization to having a portal', function () {
    expect(Organization::factory()->create()->portal_enabled)->toBeTrue();
});

it('switches a customer portal off from the console when the switch is left unchecked', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    // An unchecked switch posts no field at all, so "off" arrives at the server as an
    // absent key — the case prepareForValidation() exists to turn into an explicit false.
    $this->actingAs(superAdmin())->put(route('admin.organizations.update', $org->id), [
        'name' => $org->name,
        'slug' => $org->slug,
        'notification_cadence' => 'hourly',
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($org->fresh()->portal_enabled)->toBeFalse();
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
