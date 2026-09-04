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
use Illuminate\Support\Carbon;

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

it('does not list a package another organization has in its own registry', function () {
    // NOT in the brief, and the brief's neighbouring case cannot stand in for it: there the
    // other organization's package is attached to nothing, so its absence is explained by
    // having no assignment at all and says nothing about the organization scoping. Deleting
    // that scoping outright — every registry on the instance instead of this one's — left
    // the whole brief set green while the service listed every tenant's packages. Attaching
    // the foreign package to its own registry is what makes the boundary observable.
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create();

    $other = Organization::factory()->create();
    $otherGroup = Group::factory()->for($other)->create();
    $otherGroup->packages()->attach(
        Package::factory()->for($other)->create(['name' => 'other/thing'])->id
    );

    expect(app(PortalPackages::class)->for($org))->toBeEmpty();
});

it('does not list a package that only a registry hidden from the portal carries', function () {
    // `groups.portal_enabled` means "does this registry appear in the portal". A registry
    // with the switch off is a collection-only container, GroupPolicy::view() refuses it, and
    // naming its package here would put a link on the page that answers 403. That the package
    // then has no portal row at all is the meaning of the switch, not a side effect — it stays
    // resolvable through /r/… with a token.
    $org = Organization::factory()->create();
    $hidden = Group::factory()->for($org)->create(['name' => 'Sammlung', 'portal_enabled' => false]);
    $hidden->packages()->attach(Package::factory()->for($org)->create(['name' => 'acme/hidden'])->id);

    expect(app(PortalPackages::class)->for($org))->toBeEmpty();
});

it('lists that same package once the registry switch is on', function () {
    // The other direction, because a service that listed nothing at all would satisfy the case
    // above. Same fixture, one column different.
    $org = Organization::factory()->create();
    $shown = Group::factory()->for($org)->create(['name' => 'Sammlung', 'portal_enabled' => true]);
    $shown->packages()->attach(Package::factory()->for($org)->create(['name' => 'acme/hidden'])->id);

    expect(app(PortalPackages::class)->for($org)->pluck('package.name')->all())
        ->toBe(['acme/hidden']);
});

it('names only the portal-visible registry of a package that sits in both', function () {
    // The mixed case for the portal switch, and the one the hidden-only case cannot stand in
    // for: here the package IS listed, so a filter that merely drops rows left without any
    // visible registry passes that test and still names the hidden one here — and Task 3
    // renders it as exactly the link GroupPolicy::view() answers with 403.
    $org = Organization::factory()->create();
    $visible = Group::factory()->for($org)->create(['name' => 'A sichtbar']);
    $hidden = Group::factory()->for($org)->create(['name' => 'B versteckt', 'portal_enabled' => false]);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $visible->packages()->attach($package->id);
    $hidden->packages()->attach($package->id);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->pluck('group.id')->all())->toBe([$visible->id]);
});

it('reports each registry own availability date, independently', function () {
    // Spec §3 wants "abgelaufen am …" with the date. The date has to come from the entry: the
    // only other place a caller could reach, $row['package']->pivot, is the pivot of whichever
    // registry was seen first — here registry A, whose date is not B's.
    //
    // This service still compares no dates. It reads the stored column and hands it on; which
    // assignment is in force remains the difference between packagesFor() and packages().
    $org = Organization::factory()->create();
    $a = Group::factory()->for($org)->create(['name' => 'A']);
    $b = Group::factory()->for($org)->create(['name' => 'B']);
    $c = Group::factory()->for($org)->create(['name' => 'C']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $a->packages()->attach($package->id, ['available_until' => now()->addDays(30)]);
    $b->packages()->attach($package->id, ['available_until' => now()->subDay()]);
    $c->packages()->attach($package->id);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->pluck('available_until')->map(fn (?Carbon $d): ?string => $d?->toDateString())->all())
        ->toBe([
            now()->addDays(30)->toDateString(),
            now()->subDay()->toDateString(),
            // An assignment with no end date carries none — the null passes through unchanged.
            null,
        ])
        ->and($row['groups']->pluck('in_force')->all())->toBe([true, false, true]);
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

it('marks the lapsed registry on the entry, not the whole package', function () {
    // The mixed case, asserted at BOTH levels from one fixture. The row-level flag is derived
    // from the entries rather than accumulated beside them, so it cannot claim something the
    // entries deny: without the per-registry answer the landing page would show no marker and
    // still link to the registry that 404s the customer's build.
    $org = Organization::factory()->create();
    $live = Group::factory()->for($org)->create(['name' => 'A live']);
    $lapsed = Group::factory()->for($org)->create(['name' => 'B lapsed']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $live->packages()->attach($package->id);
    $lapsed->packages()->attach($package->id, ['available_until' => now()->subDay()]);

    $row = app(PortalPackages::class)->for($org)->first();

    // Registries are ordered by name, so the sequence is deterministic.
    expect($row['groups']->pluck('group.id')->all())->toBe([$live->id, $lapsed->id])
        // The entries DISAGREE with each other — that is the whole point of moving the flag
        // onto them.
        ->and($row['groups']->pluck('in_force')->all())->toBe([true, false])
        // …and the row still reads in force, because one registry serves it.
        ->and($row['in_force'])->toBeTrue();
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

it('names every portal-visible registry the package is assigned to', function () {
    $org = Organization::factory()->create();
    $a = Group::factory()->for($org)->create(['name' => 'A']);
    $b = Group::factory()->for($org)->create(['name' => 'B']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $a->packages()->attach($package->id);
    $b->packages()->attach($package->id);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->pluck('group.id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});
