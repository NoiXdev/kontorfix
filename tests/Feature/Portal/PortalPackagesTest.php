<?php

/*
 * The portal's package set: everything the organization can reach, from its own packages
 * and from those shared with it, with the registries that carry each one and whether the
 * assignment is still in force.
 *
 * The lapsed case is deliberately VISIBLE here, unlike on the per-registry surfaces
 * (PortalLapsedAssignmentTest), because this page carries the explanation that page has
 * nowhere to put: the customer's build gets a 404 and the landing page says why.
 */

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Portal\PortalPackages;

it('lists a package the organization owns', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $group->packages()->attach($package->id);

    $rows = app(PortalPackages::class)->for($org);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['package']->id)->toBe($package->id)
        ->and($rows->first()['in_force'])->toBeTrue();
});

it('lists a shared package assigned to one of the organization registries', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $group->packages()->attach($shared->id);

    $rows = app(PortalPackages::class)->for($org);

    expect($rows->pluck('package.id'))->toContain($shared->id)
        ->and($rows->firstWhere('package.id', $shared->id)['in_force'])->toBeTrue();
});

it('keeps a lapsed assignment in the list and marks it out of force', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $group->packages()->attach($shared->id, ['available_until' => now()->subDay()]);

    $rows = app(PortalPackages::class)->for($org);

    // Visible, not hidden: the customer's build gets a 404 for this package and the portal
    // is where that becomes explicable.
    expect($rows->pluck('package.id'))->toContain($shared->id)
        ->and($rows->firstWhere('package.id', $shared->id)['in_force'])->toBeFalse();
});

it('does not list a package of another organization', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();
    $other = Organization::factory()->create();
    Package::factory()->for($other)->create(['name' => 'other/thing']);

    expect(app(PortalPackages::class)->for($org))->toBeEmpty();
});

it('keeps a package in force while any one registry still serves it', function () {
    // NOT in the brief, and it has to be: `in_force` accumulates across registries, and no
    // case in the brief's set can tell an accumulating `||` from a plain last-wins
    // assignment — in every one of them the registries agree. The live registry is named
    // first and the lapsed one last on purpose, so the lapsed answer is the one a
    // non-accumulating implementation would keep.
    $org = Organization::factory()->create();
    $live = Group::factory()->for($org)->create(['name' => 'A live']);
    $lapsed = Group::factory()->for($org)->create(['name' => 'B lapsed']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $live->packages()->attach($package->id);
    $lapsed->packages()->attach($package->id, ['available_until' => now()->subDay()]);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['in_force'])->toBeTrue()
        // Both registries are named: the row is about the package, and the customer needs
        // to see everywhere it is assigned.
        ->and($row['groups'])->toHaveCount(2);
});

it('orders the rows by package name, not by the registry they came from', function () {
    // NOT in the brief either, and the interface promises it: "ordered by package name".
    // The registries are named so that the rows arrive in the opposite order — A first —
    // which makes the promise the only thing that can produce the expected sequence, and
    // makes dropping the sort redden deterministically rather than by UUID luck.
    $org = Organization::factory()->create();
    $a = Group::factory()->for($org)->create(['name' => 'A']);
    $b = Group::factory()->for($org)->create(['name' => 'B']);
    $a->packages()->attach(Package::factory()->for($org)->create(['name' => 'zeta/one'])->id);
    $b->packages()->attach(Package::factory()->for($org)->create(['name' => 'alpha/two'])->id);

    expect(app(PortalPackages::class)->for($org)->pluck('package.name')->all())
        ->toBe(['alpha/two', 'zeta/one']);
});

it('names every registry that serves the package', function () {
    $org = Organization::factory()->create();
    $a = Group::factory()->for($org)->create(['name' => 'A']);
    $b = Group::factory()->for($org)->create(['name' => 'B']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $a->packages()->attach($package->id);
    $b->packages()->attach($package->id);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});
