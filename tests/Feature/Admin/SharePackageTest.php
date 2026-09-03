<?php

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;

it('marks a package owned by the operator organization as shared', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create();

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.shared', $package), ['shared' => true])
        ->assertRedirect();

    expect($package->fresh()->shared)->toBeTrue();
});

it('refuses to share a package a customer owns', function () {
    $package = Package::factory()->create(); // its own, non-operator organization

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.shared', $package), ['shared' => true])
        ->assertSessionHasErrors('shared');

    expect($package->fresh()->shared)->toBeFalse();
});

it('refuses a caller the setting does not authorize', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create();

    // Default shared_package_role is SuperAdmin (see SharedPackageGateTest), so a maintainer
    // of the operator organization is exactly the population the setting does not authorize.
    $this->actingAs(operatorMaintainer())
        ->put(route('admin.packages.shared', $package), ['shared' => true])
        ->assertForbidden();

    expect($package->fresh()->shared)->toBeFalse();
});

/*
 * Un-sharing is the reverse of an attach and can invalidate assignments that were valid
 * while the flag was set. Before shared packages existed no cross-organization
 * `group_package` row could exist; now one can, and clearing the flag would leave it
 * violating the invariant 2026_09_02_110000_enforce_package_organization.php enforces —
 * and would drop the package out of those registries once resolution is scoped again, so
 * the name would fall through to the public index it was assigned there to pre-empt.
 * Refuse and name the registries, the way the migrations do; do not detach silently.
 */

it('refuses to un-share a package still assigned to another organizations registry', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create(['shared' => true]);

    $customer = Group::factory()->create(['name' => 'Kundenregistry']);
    $package->groups()->attach($customer);

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.shared', $package), ['shared' => false])
        // The registries by name, so the operator knows where to go — and the message text,
        // not just the key: a QueryException from the invariant this refusal protects would
        // also arrive as "an error".
        ->assertSessionHasErrors(['shared' => 'Dieses Paket ist noch Registrys anderer Organisationen '
            .'zugewiesen: Kundenregistry. Entfernen Sie es dort zuerst.']);

    expect($package->fresh()->shared)->toBeTrue()
        ->and($customer->packages()->whereKey($package->id)->exists())->toBeTrue();
});

it('un-shares a package assigned only within the operator organization', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create(['shared' => true]);

    // Its owner's own registry is not a cross-organization assignment, so nothing blocks it.
    $package->groups()->attach(Group::factory()->for($operator)->create());

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.shared', $package), ['shared' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($package->fresh()->shared)->toBeFalse();
});

it('un-shares a package that is assigned nowhere', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create(['shared' => true]);

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.shared', $package), ['shared' => false])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($package->fresh()->shared)->toBeFalse();
});

it('does not apply the un-share check when sharing', function () {
    // The check is one-directional by design: a cross-organization assignment cannot exist
    // before the flag is set, and setting it is what makes such assignments legal.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $package = Package::factory()->for($operator)->create(['shared' => false]);

    $this->actingAs(superAdmin())
        ->put(route('admin.packages.shared', $package), ['shared' => true])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($package->fresh()->shared)->toBeTrue();
});
