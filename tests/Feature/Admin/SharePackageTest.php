<?php

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
