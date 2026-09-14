<?php

use App\Enums\PackageType;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create();
    $this->package = Package::factory()->for($operator)->create(['shared' => true, 'type' => PackageType::Composer]);
});

it('creates a licence from the console', function () {
    $this->actingAs(superAdmin())
        ->post(route('admin.organizations.licences.store', $this->customer), [
            'package_id' => $this->package->id,
            'available_until' => null,
            'version_min' => '1.0.0',
            'version_max' => '2.9.9',
        ])->assertSessionHasNoErrors();

    /** @var Package $row */
    $row = $this->customer->licensedPackages()->first();

    expect($row->pivot->version_max)->toBe('2.9.9');
});

it('keeps the unnamed bound when only one side is edited', function () {
    $this->customer->licensedPackages()->attach($this->package->id, ['version_min' => '1.0.0', 'version_max' => '2.9.9']);

    $this->actingAs(superAdmin())
        ->put(route('admin.organizations.licences.update', [$this->customer, $this->package]), [
            'available_until' => null,
            'version_max' => '1.9.9',
        ])->assertSessionHasNoErrors();

    /** @var Package $row */
    $row = $this->customer->licensedPackages()->first();

    expect($row->pivot->version_min)->toBe('1.0.0')->and($row->pivot->version_max)->toBe('1.9.9');
});

it('removes a licence', function () {
    $this->customer->licensedPackages()->attach($this->package->id);

    $this->actingAs(superAdmin())
        ->delete(route('admin.organizations.licences.destroy', [$this->customer, $this->package]))
        ->assertSessionHasNoErrors();

    expect($this->customer->licensedPackages()->count())->toBe(0);
});

it('404s an update for a package this organization holds no licence for', function () {
    $this->actingAs(superAdmin())
        ->put(route('admin.organizations.licences.update', [$this->customer, $this->package]), [
            'available_until' => null,
        ])->assertNotFound();
});

it('refuses a caller who administers neither organization', function () {
    $outsider = User::factory()->for(Organization::factory())->create(['role' => UserRole::Admin]);

    $this->actingAs($outsider)
        ->post(route('admin.organizations.licences.store', $this->customer), [
            'package_id' => $this->package->id,
            'available_until' => null,
        ])->assertForbidden();
});
