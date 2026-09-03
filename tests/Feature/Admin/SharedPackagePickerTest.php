<?php

/*
 * What the assignment picker may offer (spec §6): own packages AND shared ones, visually
 * distinguished.
 *
 * The set is derived from what GuardsPackageAttachment will actually accept — "owned by
 * this organization, or shared" — because a picker that offers less than that hides the
 * feature, and one that offers more produces a 403 on submit and teaches the operator that
 * the console lies. So both directions are asserted here: the shared package appears, and
 * v0.8.0's guarantee that a *non*-shared package of another organization does not is
 * unchanged.
 *
 * The searching user is an admin of a plain customer organization throughout — a
 * super-admin's scope spans every organization and would see every package with or without
 * the shared clause, so a test written with one could not tell the two apart.
 */

use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

/** A shared package: owned by an operator organization and marked, as spec §1 requires. */
function pickerSharedPackage(string $name = 'betrieb/toolkit'): Package
{
    return Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['name' => $name, 'shared' => true]);
}

/** An admin of a plain customer organization, whose scope is that organization alone. */
function pickerCustomerAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => 'admin']);
}

it('offers a shared package of the operator organization', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    pickerSharedPackage();

    $this->actingAs(pickerCustomerAdmin($customer))
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'betrieb/toolkit');
});

it('still hides a non-shared package of another organization', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['name' => 'betrieb/toolkit', 'shared' => false]);

    $this->actingAs(pickerCustomerAdmin($customer))
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(0);
});

it('still offers the searching organizations own packages', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    Package::factory()->for($customer)->create(['name' => 'kunde/toolkit']);

    $this->actingAs(pickerCustomerAdmin($customer))
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'kunde/toolkit');
});

it('marks which of the offered packages are shared', function () {
    $customer = Organization::factory()->create(['is_operator' => false]);
    Package::factory()->for($customer)->create(['name' => 'aaa/eigen']);
    pickerSharedPackage('zzz/geteilt');

    // Ordered by name, so `aaa/…` is the own package and `zzz/…` the shared one. Without
    // this flag the picker cannot distinguish them and the operator cannot see that they
    // are handing over a package other tenants also receive.
    $this->actingAs(pickerCustomerAdmin($customer))
        ->getJson('/admin/package-search?q=/')
        ->assertOk()
        ->assertJsonPath('0.shared', false)
        ->assertJsonPath('1.shared', true);
});
