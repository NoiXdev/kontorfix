<?php

use App\Enums\PackageType;
use App\Models\Organization;
use App\Models\Package;
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
