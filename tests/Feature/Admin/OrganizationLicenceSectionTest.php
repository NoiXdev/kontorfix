<?php

use App\Enums\PackageType;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

it('sends this organization licences to the customer page', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz']);
    $customer->licensedPackages()->attach($package->id, ['version_min' => '1.0.0']);

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licences.0.package_name', 'acme/lizenz')
            ->where('licences.0.version_min', '1.0.0'));
});

it('offers only shared packages for licensing', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/geteilt']);
    Package::factory()->for($operator)->create(['shared' => false, 'type' => PackageType::Composer, 'name' => 'acme/privat']);

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licensable_packages', fn (Collection $packages) => $packages->pluck('name')->contains('acme/geteilt')
                && ! $packages->pluck('name')->contains('acme/privat')));
});

it('excludes docker packages from the licensable list', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Docker, 'name' => 'acme/image']);

    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('licensable_packages', fn (Collection $packages) => ! $packages->pluck('name')->contains('acme/image')));
});

it('shows a licence created from the package page on the customer page, and vice versa', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $customer = Organization::factory()->create();
    $package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);

    // Created through the one write surface both hosts submit to…
    $this->actingAs(superAdmin())->post(route('admin.organizations.licences.store', $customer), [
        'package_id' => $package->id,
        'available_until' => null,
        'version_max' => '2.9.9',
    ])->assertSessionHasNoErrors();

    // …and visible from both, because it is one row and not two.
    $this->actingAs(superAdmin())->get(route('admin.organizations.show', $customer))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('licences.0.version_max', '2.9.9'));

    // `organization_licences` sits at the top level of the payload, a sibling of `package`
    // (see PackageLicencePayloadTest) — not nested inside it.
    $this->actingAs(superAdmin())->get(route('admin.packages.show', $package))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('organization_licences.0.version_max', '2.9.9'));
});
