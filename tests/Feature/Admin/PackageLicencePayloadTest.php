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

it('reports a pre-release bound as narrowed, which a naive string comparison would call equal', function () {
    // A numeric-segment comparator (split on '.', '-', '+', parse each run as an int) reads
    // '2.0.0-beta1' as {2,0,0} — the '-beta1' suffix parses to nothing and is dropped — so it
    // rates '2.0.0-beta1' and '2.0.0' EQUAL instead of ranking the pre-release lower. That
    // was `lizenz.ts`'s original defect: a licence floor of 2.0.0 would not have narrowed a
    // row starting at 2.0.0-beta1 at all. Asserted here, at the payload layer, rather than
    // only in VersionEntitlementTest, because a future refactor that reintroduces a
    // client-side comparator would not trip a guard living one layer away from the payload.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
    $customer->licensedPackages()->attach($package->id, ['version_min' => '2.0.0']);

    $registry = Group::factory()->for($customer)->create();
    $registry->packages()->attach($package->id, ['version_min' => '2.0.0-beta1']);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assignments.0.version_min', '2.0.0-beta1')
            ->where('assignments.0.narrowed_by_licence', true)
            ->where('assignments.0.effective_version_min', '2.0.0'));
});

it('reports a PEP 440 post-release bound as narrowed too, the other form a naive comparator mangles', function () {
    // The same numeric-segment comparator maps '1.0.post1' to {1,0,0} — the same shape as
    // plain '1.0' — rating them EQUAL instead of ranking the post-release higher, which is
    // the other version form `lizenz.ts`'s original comparator got wrong.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Python]);
    $customer->licensedPackages()->attach($package->id, ['version_min' => '1.0.post1']);

    $registry = Group::factory()->for($customer)->create();
    $registry->packages()->attach($package->id, ['version_min' => '1.0']);

    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('assignments.0.version_min', '1.0')
            ->where('assignments.0.narrowed_by_licence', true)
            ->where('assignments.0.effective_version_min', '1.0.post1'));
});
