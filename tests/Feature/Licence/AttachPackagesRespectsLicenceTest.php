<?php

// `AssignmentWriter::assertWithinOrganizationLicence()` refuses a registry assignment that
// the organization's licence does not admit — but only on the two paths that go through the
// writer. `GroupController::attachPackages()` writes `group_package` directly, so "Pakete
// zur Registry hinzufügen" accepted what "Registry freigeben" refused.
//
// No access was widened by that: a row outside the licence serves nothing, because
// boundsFor() applies the ceiling on every read. What was wrong is that the rule held at
// some write paths and not others, which is how a guard quietly stops being one.

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Package\AssignmentWriter;
use App\Support\Licence\VersionBounds;

beforeEach(function () {
    // The writer asks who is acting: granting a licence requires administering both the
    // target organization and the package's owner.
    $this->actingAs(superAdmin());

    $operator = Organization::factory()->create(['is_operator' => true]);
    $this->customer = Organization::factory()->create();
    $this->group = Group::factory()->for($this->customer)->create();
    $this->package = Package::factory()->for($operator)->create([
        'shared' => true, 'type' => PackageType::Composer, 'name' => 'acme/lizenz',
    ]);
});

it('refuses adding a package whose organization licence has already expired', function () {
    app(AssignmentWriter::class)->assignToOrganization(
        $this->customer, $this->package, now()->subDay(), VersionBounds::unlimited(),
    );

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $this->group), ['package_ids' => [$this->package->id]])
        ->assertSessionHasErrors();

    expect($this->group->packages()->count())->toBe(0);
});

it('still adds a package under a live licence', function () {
    app(AssignmentWriter::class)->assignToOrganization(
        $this->customer, $this->package, null, new VersionBounds('1.0.0', '2.9.9'),
    );

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $this->group), ['package_ids' => [$this->package->id]])
        ->assertSessionHasNoErrors();

    expect($this->group->packages()->count())->toBe(1);
});

it('still adds a package when the organization holds no licence at all', function () {
    // The overwhelmingly common case, and the one that must not change: customers without
    // any org licence behave exactly as they did before this feature existed.
    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $this->group), ['package_ids' => [$this->package->id]])
        ->assertSessionHasNoErrors();

    expect($this->group->packages()->count())->toBe(1);
});

it('adds nothing at all when one package of a batch is refused', function () {
    // syncWithoutDetaching() would otherwise attach the acceptable ones and drop the
    // refused one silently, leaving the operator with a partial result they did not ask
    // for and no indication which half happened.
    $other = Package::factory()->for($this->customer)->create([
        'type' => PackageType::Composer, 'name' => 'kunde/eigen',
    ]);
    app(AssignmentWriter::class)->assignToOrganization(
        $this->customer, $this->package, now()->subDay(), VersionBounds::unlimited(),
    );

    $this->actingAs(superAdmin())
        ->post(route('admin.groups.packages.store', $this->group), [
            'package_ids' => [$other->id, $this->package->id],
        ])->assertSessionHasErrors();

    expect($this->group->packages()->count())->toBe(0);
});
