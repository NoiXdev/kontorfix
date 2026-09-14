<?php

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

it('sends the organization licences to the package page', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create(['name' => 'Kunde AG']);
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
    $customer->licensedPackages()->attach($package->id, ['version_max' => '2.9.9']);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            // `organization_licences` sits at the top level of the payload, a sibling of
            // `package`, exactly where `assignable_groups` already lives — not nested
            // inside `package` itself.
            ->where('organization_licences.0.organization_name', 'Kunde AG')
            ->where('organization_licences.0.version_max', '2.9.9')
            ->where('organization_licences.0.expired', false));
});

it('offers no licensable organizations for a package that is not shared', function () {
    $package = Package::factory()->create(['shared' => false, 'type' => PackageType::Composer]);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('licensable_organizations', []));
});

it('marks a licence expired once its term has lapsed', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create(['name' => 'Kunde AG']);
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
    // An EXPIRED licence, not merely absent — OrganizationLicence's whole point is that the
    // two are different answers (see its docblock), and this pins the payload states the
    // difference too, rather than only ever exercising the "live licence" branch above.
    $customer->licensedPackages()->attach($package->id, ['available_until' => now()->subDay()]);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organization_licences.0.organization_name', 'Kunde AG')
            ->where('organization_licences.0.expired', true));
});

it('excludes an organization that already holds a licence from the licensable-organizations picker', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $licensed = Organization::factory()->create(['name' => 'Bereits lizenziert AG']);
    $unlicensed = Organization::factory()->create(['name' => 'Noch offen AG']);
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
    $licensed->licensedPackages()->attach($package->id);

    // Not an exact-count assertion: superAdmin() itself creates a fresh operator
    // organization as the caller's home org, which — spanning all organizations —
    // legitimately also appears in the picker. The behaviour under test is the EXCLUSION
    // of an already-licensed organization, so this checks presence/absence by name
    // instead of the total size.
    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licensable_organizations', fn (Collection $organizations): bool => $organizations->contains('name', 'Noch offen AG')
                && ! $organizations->contains('name', 'Bereits lizenziert AG')));
});

it('withholds every licence field from a customer who only receives a shared package', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    // Holds a real licence — proving the payload is withheld for the receiving viewer
    // below, not merely empty because none exists at all.
    $licensedElsewhere = Organization::factory()->create(['name' => 'Andere Kunde AG']);
    $receiving = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
    $licensedElsewhere->licensedPackages()->attach($package->id, ['version_max' => '2.9.9']);

    // The receiving organization's OWN registry carries the shared package — this is what
    // lets assertCanViewPackage() admit this viewer to the page at all (see its docblock),
    // while canManageAssignments() still answers false for them.
    $registry = Group::factory()->for($receiving)->create();
    $registry->packages()->attach($package->id);
    $viewer = User::factory()->for($receiving)->create(['role' => UserRole::Admin]);

    $this->actingAs($viewer)->get(route('admin.packages.show', $package))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('can_manage_assignments', false)
            ->where('organization_licences', [])
            ->where('licensable_organizations', []));
});
