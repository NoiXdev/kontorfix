<?php

/*
 * `group_package.available_until` becomes writable here (spec §6), and readable in the
 * registry's package table.
 *
 * Two things this file is derived from, neither of them from the code below it:
 *
 * 1. THE LISTING MUST NOT LIE. Before this task the registry page listed assignments from
 *    Group::packages() — the unfiltered set — and never selected the column, so an expired
 *    assignment was indistinguishable from a live one. The operator read "assigned" while
 *    the registry served nothing and the customer's build got a 404. So the payload is
 *    asserted for a lapsed row AND a live dated one: a page that marked everything in force
 *    and a page that marked everything expired would each satisfy half of it.
 *
 * 2. "VERFÜGBAR BIS <TAG>" INCLUDES THAT DAY. A date is a day, and the stored column is an
 *    instant; the only two readings are "from its first moment" and "through its last", and
 *    the label the operator reads says the second. Both sides of that boundary are pinned,
 *    because a `startOfDay()` would still expire the assignment eventually and every test
 *    phrased in whole days would stay green.
 *
 * The refusal that protects the shared-package invariant on this new write path lives with
 * the other guard cases, in SharedPackageAssignmentTest.
 */

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Carbon;

afterEach(fn () => Carbon::setTestNow());

it('says of each assignment whether the registry actually serves it', function () {
    $group = Group::factory()->create();
    $live = Package::factory()->inOrgOf($group)->create(['name' => 'aaa/live']);
    $dated = Package::factory()->inOrgOf($group)->create(['name' => 'mmm/dated']);
    $lapsed = Package::factory()->inOrgOf($group)->create(['name' => 'zzz/lapsed']);

    $group->packages()->attach($live);
    $group->packages()->attach($dated, ['available_until' => now()->addDays(30)]);
    $group->packages()->attach($lapsed, ['available_until' => now()->subDay()]);

    $this->actingAs(superAdmin())->get(route('admin.groups.show', $group))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Ordered by name, so the three rows are live, dated, lapsed.
            ->where('packages.0.available_until', null)
            ->where('packages.0.in_force', true)
            ->where('packages.1.available_until', now()->addDays(30)->toDateString())
            ->where('packages.1.in_force', true)
            // The row the old page showed as though it were live.
            ->where('packages.2.available_until', now()->subDay()->toDateString())
            ->where('packages.2.in_force', false)
            ->etc());
});

it('still lists a lapsed assignment, because detaching it is the only way out', function () {
    // Filtering it out of the listing would be the obvious "fix" for the defect above and
    // is the wrong one: a lapsed assignment keeps the name blocked from the public index,
    // and the operator cannot detach what the page does not show.
    $group = Group::factory()->create();
    $lapsed = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($lapsed, ['available_until' => now()->subYear()]);

    $this->actingAs(superAdmin())->get(route('admin.groups.show', $group))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('packages', 1)->etc());
});

it('says of each assignment whether the package is shared', function () {
    $group = Group::factory()->create();
    $own = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($own);

    $this->actingAs(superAdmin())->get(route('admin.groups.show', $group))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('packages.0.shared', false)->etc());
});

it('keeps serving a package through the whole day it is available until', function () {
    Carbon::setTestNow('2026-06-01 09:00:00');
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($package);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => '2026-06-30'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // The last moment of the named day: still served. A start-of-day reading would already
    // have stopped serving it 24 hours ago.
    Carbon::setTestNow('2026-06-30 23:59:00');
    expect($group->assignedPackages()->whereKey($package->id)->exists())->toBeTrue();

    // And the day after: no longer served.
    Carbon::setTestNow('2026-07-01 00:01:00');
    expect($group->assignedPackages()->whereKey($package->id)->exists())->toBeFalse();
});

it('lets an assignment be made open-ended again', function () {
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($package, ['available_until' => now()->subDay()]);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => null])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($group->packages()->whereKey($package->id)->sole()->pivot->available_until)->toBeNull()
        ->and($group->assignedPackages()->whereKey($package->id)->exists())->toBeTrue();
});

it('accepts today, so an assignment can be ended at the end of today', function () {
    Carbon::setTestNow('2026-06-01 09:00:00');
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($package);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => '2026-06-01'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($group->assignedPackages()->whereKey($package->id)->exists())->toBeTrue();
});

it('refuses a date that has already passed', function () {
    // "Verfügbar bis gestern" is not a statement about availability, it is a detach with a
    // worse outcome: the registry stops serving AND the name stays blocked from the public
    // index. Detaching is the act that means that, and it already exists.
    Carbon::setTestNow('2026-06-01 09:00:00');
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($package);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => '2026-05-31'])
        ->assertSessionHasErrors('available_until');

    expect($group->packages()->whereKey($package->id)->sole()->pivot->available_until)->toBeNull();
});

it('refuses something that is not a date', function () {
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($package);

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => 'irgendwann'])
        ->assertSessionHasErrors('available_until');
});

it('refuses to date an assignment that does not exist', function () {
    // updateExistingPivot() would report zero rows and the request would look successful.
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();

    $this->actingAs(superAdmin())
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => null])
        ->assertNotFound();
});

it('refuses a caller who does not administer the registry', function () {
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create();
    $group->packages()->attach($package, ['available_until' => now()->addDay()]);

    $outsider = adminOf(Organization::factory()->create(['is_operator' => false]));

    $this->actingAs($outsider)
        ->put(route('admin.groups.packages.update', [$group, $package]), ['available_until' => null])
        ->assertForbidden();

    expect($group->packages()->whereKey($package->id)->sole()->pivot->available_until)->not->toBeNull();
});
