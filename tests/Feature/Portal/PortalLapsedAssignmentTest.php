<?php

/*
 * The customer portal against what the registry actually serves.
 *
 * `group_package.available_until` became writable with the shared-packages feature (spec §6),
 * and the admin console learned to say per assignment whether the registry still serves it
 * (`in_force`, decided by Group::assignedPackages()). All three of the portal's read surfaces
 * kept reading Group::packages() — the unfiltered pivot — so an operator who put an expiry on
 * an assignment left the customer with a portal that listed the package, counted it, showed
 * its latest version and served its readme, its version list and its dependency tree, while
 * `composer install` answered 404 for the same package.
 *
 * That is the same console-disagreeing-with-the-registry defect `in_force` was added to fix,
 * on the page the customer reads rather than the one the operator reads.
 *
 * The first answer was to HIDE such an assignment, taken because the portal had no
 * "abgelaufen" badge and no explanatory note to put on the row. It has both now, so the
 * answer changed: the row is listed and MARKED, and the detail page is served with the
 * explanation instead of a 404. Hiding was the worse half of the same defect — the customer
 * whose build 404s arrived at a portal that did not mention the package at all, which reads
 * as "it was never there" rather than as "it lapsed".
 *
 * The COUNT beside a registry is the one thing that still excludes lapsed rows: it is the
 * customer's shortest answer to "what is in here", and a number that counts what the registry
 * refuses to serve is simply wrong.
 *
 * Each surface is asserted in BOTH directions — what a live assignment gets and what a lapsed
 * one gets — because a page that marked everything, or nothing, would satisfy half of every
 * case here.
 */

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

beforeEach(function () {
    $this->org = Organization::factory()->create();
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create();

    $this->live = Package::factory()->inOrgOf($this->group)->create(['name' => 'acme/live']);
    $this->lapsed = Package::factory()->inOrgOf($this->group)->create(['name' => 'acme/lapsed']);

    $this->group->packages()->attach($this->live);
    $this->group->packages()->attach($this->lapsed, ['available_until' => now()->subDay()]);
});

it('counts only the assignments the registry still serves', function () {
    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registries')
            ->has('registries', 1)
            // Two pivot rows, one of them lapsed. The number beside the registry is the
            // customer's shortest answer to "what is in here", and it counted both.
            ->where('registries.0.packages_count', 1));
});

it('lists a lapsed assignment alongside the live one, marked', function () {
    // Ordered by name, so acme/lapsed precedes acme/live. Both rows are present and the
    // flags differ between them, which no constant can produce.
    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->has('packages', 2)
            ->where('packages.0.name', 'acme/lapsed')
            ->where('packages.0.in_force', false)
            ->where('packages.1.name', 'acme/live')
            ->where('packages.1.in_force', true)
            ->etc());
});

it('keeps the version a lapsed row had rather than blanking it', function () {
    // The version is what the customer's lock file names, so it is what they match the row
    // against when the build fails. Blanking it would leave them unable to tell whether this
    // is even the package their build asked for; the `abgelaufen` marker, not a missing
    // field, is what says the registry stopped serving it.
    PackageVersion::factory()->create([
        'package_id' => $this->lapsed->id,
        'version_pretty' => '9.9.9',
    ]);

    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->where('packages.0.name', 'acme/lapsed')
            ->where('packages.0.latest_version', '9.9.9')
            ->where('packages.0.in_force', false)
            ->etc());
});

it('serves the detail page of an assignment that is still in force', function () {
    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$this->live->id}")
        ->assertOk();
});

it('serves the detail page of a lapsed assignment so it can say why the build fails', function () {
    // 404 was the previous answer, chosen to match what the registry answers for the same
    // name. It matched the registry and told the customer nothing: they follow this link
    // BECAUSE their build 404d. The page is served and withholds only the install command,
    // whose place the explanation takes.
    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$this->lapsed->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Package')->where('in_force', false)->etc());
});

it('still answers 404 for a package this registry was never given', function () {
    // The guard that survived the change. `packages()` asks whether the assignment exists at
    // all, and a package assigned to no registry is a guessed URL with nothing to explain.
    $unassigned = Package::factory()->inOrgOf($this->group)->create(['name' => 'acme/never']);

    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$unassigned->id}")
        ->assertNotFound();
});

it('serves an assignment again once its availability is pushed back into the future', function () {
    // The predicate has a direction, and a guard that answered "expired" for every dated row
    // would pass every case above. A date in the future is a live assignment.
    $this->group->packages()->updateExistingPivot($this->lapsed->id, [
        'available_until' => now()->addDay(),
    ]);

    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$this->lapsed->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('in_force', true)->etc());

    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('registries.0.packages_count', 2));
});
