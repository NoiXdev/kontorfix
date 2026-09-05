<?php

/*
 * What the assignment picker may offer (spec §6): own packages AND shared ones whose owning
 * organization the searcher administers, visually distinguished.
 *
 * The set is derived from what GuardsPackageAttachment will actually accept — "owned by this
 * organization, or shared and owned by an organization this caller administers" — because a
 * picker that offers less than that hides the feature, and one that offers more produces a
 * 403 on submit and teaches the operator that the console lies. So both directions are
 * asserted here: the shared package appears for someone who may assign it, does not appear
 * for someone who may not, and v0.8.0's guarantee that a *non*-shared package of another
 * organization never appears is unchanged.
 *
 * THE SECOND CLAUSE IS NEW, and it is the picker's half of spec §4 becoming enforced.
 * Sharing and assigning are two gates: `shared` says a package may leave its organization,
 * the assignment says which customer receives it, and the operator makes that decision per
 * customer. While the picker offered every shared package to every organization admin, the
 * second gate did not exist — a customer helped themselves. It is also a disclosure fix:
 * the names of the packages the operator shares with its other customers are not a
 * customer's to search.
 *
 * The searching user is never a super-admin viewing "all orgs" — that scope skips the filter
 * entirely (scopeAssignablePackageQuery), so a test written with one could not tell any of
 * these clauses apart. Where a searcher who MAY assign shared packages is needed, the scope
 * is pinned to the receiving organization, which is the state an operator is actually in
 * when they assign one.
 */

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create(['is_operator' => false]);
});

/** A shared package: owned by an operator organization and marked, as spec §1 requires. */
function pickerSharedPackage(Organization $operator, string $name = 'betrieb/toolkit'): Package
{
    return Package::factory()->for($operator)->create(['name' => $name, 'shared' => true]);
}

/** An admin of a plain customer organization, whose scope is that organization alone. */
function pickerCustomerAdmin(Organization $org): User
{
    return User::factory()->for($org)->create(['role' => UserRole::Admin]);
}

/**
 * Someone who may actually place a shared package into the given customer's registry:
 * admin of that customer organization AND maintainer of the operator organization that owns
 * the shared packages. Deliberately not a super-admin — the rule is about administering the
 * owner, not about a global flag, and this account has neither `is_super_admin` nor the
 * grandfather clause (its home role is Admin, but of a non-operator organization).
 */
function pickerOperatorStaff(Organization $customer, Organization $operator): User
{
    $user = pickerCustomerAdmin($customer);
    $user->organizations()->attach($operator->id, ['role' => UserRole::Maintainer->value]);

    return $user;
}

it('offers a shared package to someone who administers the organization that owns it', function () {
    pickerSharedPackage($this->operator);

    // Scoped to the customer being served, so the first clause (own packages) cannot be what
    // returns this row: the package belongs to the operator organization, not to the scope.
    $this->actingAs(pickerOperatorStaff($this->customer, $this->operator))
        ->withSession(['admin.scope_org_id' => $this->customer->id])
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'betrieb/toolkit');
});

it('does not offer a shared package to an admin of the receiving organization alone', function () {
    // The gate this file exists for. The package is shared, so it MAY leave its
    // organization; this admin is not the one who decides that it comes to theirs.
    pickerSharedPackage($this->operator);

    $this->actingAs(pickerCustomerAdmin($this->customer))
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(0);
});

it('does not offer a shared package to an admin of some other organization than its owner', function () {
    // Administering more than one organization is not the question — administering the one
    // that OWNS the package is. Without that distinction the clause would read "anyone with
    // a second membership", which is not a permission at all.
    pickerSharedPackage($this->operator);

    $searcher = pickerCustomerAdmin($this->customer);
    $searcher->organizations()->attach(
        Organization::factory()->create(['is_operator' => false])->id,
        ['role' => UserRole::Admin->value],
    );

    $this->actingAs($searcher)
        ->withSession(['admin.scope_org_id' => $this->customer->id])
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(0);
});

it('still hides a non-shared package of another organization', function () {
    Package::factory()->for($this->operator)->create(['name' => 'betrieb/toolkit', 'shared' => false]);

    $this->actingAs(pickerCustomerAdmin($this->customer))
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(0);
});

it('still hides a non-shared package even from someone who administers its organization', function () {
    // The two clauses are an AND, and this is the half a `shared`-less mutation would let
    // through: administering the owner does not by itself make a package assignable
    // elsewhere, because that is the ownership rule v0.8.0 established.
    Package::factory()->for($this->operator)->create(['name' => 'betrieb/toolkit', 'shared' => false]);

    $this->actingAs(pickerOperatorStaff($this->customer, $this->operator))
        ->withSession(['admin.scope_org_id' => $this->customer->id])
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(0);
});

it('still offers the searching organizations own packages', function () {
    Package::factory()->for($this->customer)->create(['name' => 'kunde/toolkit']);

    $this->actingAs(pickerCustomerAdmin($this->customer))
        ->getJson('/admin/package-search?q=toolkit')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.name', 'kunde/toolkit');
});

it('marks which of the offered packages are shared', function () {
    Package::factory()->for($this->customer)->create(['name' => 'aaa/eigen']);
    pickerSharedPackage($this->operator, 'zzz/geteilt');

    // Ordered by name, so `aaa/…` is the own package and `zzz/…` the shared one. Without
    // this flag the picker cannot distinguish them and the operator cannot see that they
    // are handing over a package other tenants also receive.
    $this->actingAs(pickerOperatorStaff($this->customer, $this->operator))
        ->withSession(['admin.scope_org_id' => $this->customer->id])
        ->getJson('/admin/package-search?q=/')
        ->assertOk()
        ->assertJsonPath('0.shared', false)
        ->assertJsonPath('1.shared', true);
});
