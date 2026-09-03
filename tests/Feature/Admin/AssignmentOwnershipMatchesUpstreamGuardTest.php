<?php

/*
 * The console's `owned_by_registry_org` against what the resolver actually does.
 *
 * `Admin\GroupController::assignedPackagePayload()` sends that flag, and the German
 * availability copy turns on it and nothing else: where it is false the operator is told
 * "Entfernen Sie die Zuweisung, um den Namen freizugeben", and where it is true they are
 * told the opposite — that detaching frees nothing and only deleting the package does.
 *
 * The flag is therefore a SECOND STATEMENT of clause 1 of
 * `ResolvesRegistryPackage::packageExistsLocally()`, which suppresses the upstream for any
 * name this registry's organization owns, with no assignment involved. Two statements of one
 * rule, correct the day they are written, is the shape that has cost this branch the most —
 * the guard-versus-serving predicate in Task 3, the site table that missed PyPI in Task 4.
 * Documenting the coupling does not enforce it, and the consequence of drift here is not a
 * wrong colour: it is an operator performing a destructive act, on this console's written
 * instruction, that leaves their customer's 404 exactly where it was.
 *
 * So the coupling is asserted rather than described. For each case that matters, the
 * biconditional:
 *
 *     the console says the organization owns the name
 *       ⟺ detaching the assignment leaves the upstream still suppressed
 *
 * Both halves are checked against the real predicate, before and after the detach, so a
 * case where the name was never suppressed at all cannot pass by accident.
 */

use App\Enums\PackageType;
use App\Http\Controllers\Registry\ResolvesRegistryPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\RegistryAccessService;
use Tests\TestCase;

/**
 * `ResolvesRegistryPackage::packageExistsLocally()`, which is protected on the trait.
 *
 * A second accessor for the same method as `hostsLocally()` in
 * tests/Feature/Registry/SharedPackageUpstreamTest.php, deliberately not shared with it: a
 * top-level function only exists once its declaring file has been required, so using that
 * one here would break this file when run on its own, and lifting it into tests/Pest.php
 * means editing a completed task's test file for a one-test round. It is an accessor, not a
 * rule — both call the same method — so there is nothing here that can drift. If a third
 * caller appears, that is the moment to lift it.
 */
function suppressesUpstream(PackageType $type, string $fullName, Group $group): bool
{
    $probe = new class
    {
        use ResolvesRegistryPackage;

        public function hosts(PackageType $type, string $fullName, Group $group): bool
        {
            return $this->packageExistsLocally($type, $fullName, $group);
        }

        protected function access(): RegistryAccessService
        {
            return app(RegistryAccessService::class);
        }
    };

    return $probe->hosts($type, $fullName, $group);
}

/** The `owned_by_registry_org` this registry's console page reports for this assignment. */
function reportedOwnership(TestCase $test, Group $group, Package $package): bool
{
    $page = $test->actingAs(superAdmin())->get(route('admin.groups.show', $group))->viewData('page');

    $row = collect($page['props']['packages'])->firstWhere('id', $package->id);

    expect($row)->not->toBeNull();

    return (bool) $row['owned_by_registry_org'];
}

/**
 * The one assertion this file exists for. Stated once so the three cases below cannot drift
 * into three slightly different claims.
 */
function assertConsoleAgreesWithTheResolver(TestCase $test, Group $group, Package $package): void
{
    $reported = reportedOwnership($test, $group, $package);

    // Assigned: the registry suppresses the upstream for this name whichever clause holds.
    // Without this half, a fixture that was never suppressed at all would satisfy the
    // "detaching releases it" case for the wrong reason.
    expect(suppressesUpstream($package->type, $package->name, $group))->toBeTrue();

    $group->packages()->detach($package->id);

    // Detached: still suppressed exactly when the console said the organization owns it.
    expect(suppressesUpstream($package->type, $package->name, $group))->toBe($reported);
}

it('agrees with the resolver for an own package in its own registry', function () {
    $group = Group::factory()->create();
    $package = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'kunde/eigen']);
    $group->packages()->attach($package);

    // Ownership holds with or without the assignment, so detaching releases nothing and the
    // copy must not offer it as the release path.
    assertConsoleAgreesWithTheResolver($this, $group, $package);
});

it('agrees with the resolver for a shared package in a customer registry', function () {
    $group = Group::factory()->create();
    $package = Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['type' => 'composer', 'name' => 'betrieb/geteilt', 'shared' => true]);
    $group->packages()->attach($package);

    // Only the assignment holds the name here, so detaching really is the release path.
    assertConsoleAgreesWithTheResolver($this, $group, $package);
});

it('agrees with the resolver for a shared package in an operator registry', function () {
    // The case `shared` alone gets wrong, and the reason the payload states ownership
    // instead: both clauses hold, so detaching frees nothing here either.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $group = Group::factory()->create(['organization_id' => $operator->id]);
    $package = Package::factory()->for($operator)->create(['type' => 'composer', 'name' => 'betrieb/geteilt', 'shared' => true]);
    $group->packages()->attach($package);

    assertConsoleAgreesWithTheResolver($this, $group, $package);
});
