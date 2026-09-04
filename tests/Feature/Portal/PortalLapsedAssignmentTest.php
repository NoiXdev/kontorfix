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
 * on the page the customer reads rather than the one the operator reads — and worse there,
 * because the portal has no "abgelaufen" badge and no explanatory note to put on such a row.
 * The portal has nothing to say about a lapsed assignment, so it must not show one.
 *
 * Each surface is asserted in BOTH directions — a live assignment stays, a lapsed one goes —
 * because a portal that showed nothing at all would satisfy half of every case here.
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

it('lists only the assignments the registry still serves', function () {
    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->has('packages', 1)
            ->where('packages.0.name', 'acme/live')
            ->etc());
});

it('does not offer a version of a package the registry no longer serves', function () {
    // The listing carries `latest_version`, so a lapsed row did not merely appear — it
    // advertised something installable. Asserted through the version rather than only
    // through the count, so a fix that kept the row and blanked the version would fail.
    PackageVersion::factory()->create([
        'package_id' => $this->lapsed->id,
        'version_pretty' => '9.9.9',
    ]);

    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries/{$this->group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->has('packages', 1)
            ->where('packages.0.name', 'acme/live')
            ->where('packages.0.latest_version', null)
            ->etc());
});

it('serves the detail page of an assignment that is still in force', function () {
    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$this->live->id}")
        ->assertOk();
});

it('answers 404 for the detail page of a lapsed assignment, as the registry does', function () {
    $this->actingAs($this->member)
        ->get("/c/{$this->org->slug}/registries/{$this->group->id}/packages/{$this->lapsed->id}")
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
        ->assertOk();

    $this->actingAs($this->member)->get("/c/{$this->org->slug}/registries")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('registries.0.packages_count', 2));
});
