<?php

use App\Models\Group;
use App\Models\Organization;
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
    Group::factory()->for($org)->create(['slug' => 'main', 'public' => true]);

    // The registry endpoints are a different surface; a disabled portal breaks no build.
    $this->get('/r/acme/main/packages.json')->assertOk();
});

it('lets an operator account open a customer portal', function () {
    $customer = Organization::factory()->create(['slug' => 'acme']);
    Organization::factory()->create(['is_operator' => true]);

    $this->actingAs(superAdmin())->get('/c/acme')->assertOk();
});

it('redirects the old portal path to the home organization', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/portal')->assertRedirect('/c/acme');
});

it('defaults an organization to having a portal', function () {
    expect(Organization::factory()->create()->portal_enabled)->toBeTrue();
});
