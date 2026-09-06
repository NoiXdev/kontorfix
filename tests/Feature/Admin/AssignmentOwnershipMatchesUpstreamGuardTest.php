<?php

/*
 * The console's `owned_by_registry_org` against what the resolver actually does.
 *
 * `Admin\GroupController::assignedPackagePayload()` sends that flag, and the German
 * availability copy turns on it and nothing else: where it is false the operator is told
 * "Entfernen Sie die Zuweisung, um den Namen freizugeben", and where it is true they are told
 * the opposite — that detaching frees nothing, and that the name comes free only once no
 * package of the organization carries it any more.
 *
 * The flag is therefore a SECOND STATEMENT of clause 1 of
 * `ResolvesRegistryPackage::packageExistsLocally()` and of
 * `PypiController::pythonExistsLocally()`, which suppress the upstream whenever this
 * registry's organization carries A PACKAGE of that `(type, name)` — not necessarily the row
 * being described, and with no assignment involved. The field is type-agnostic, so
 * both resolvers are pinned here — PyPI matches PEP 503-normalised names and the other two
 * match verbatim, which is a second way the two statements can drift apart. Two statements of one
 * rule, correct the day they are written, is the shape that has cost this branch the most —
 * the guard-versus-serving predicate in Task 3, the site table that missed PyPI in Task 4.
 * Documenting the coupling does not enforce it, and the consequence of drift here is not a
 * wrong colour: it is an operator performing a destructive act, on this console's written
 * instruction, that leaves their customer's 404 exactly where it was.
 *
 * So the coupling is asserted rather than described. For each case that matters, the
 * biconditional:
 *
 *     the console says the organization carries a package of this (type, name)
 *       ⟺ detaching the assignment leaves the upstream still suppressed
 *
 * Both halves are checked against the real predicate, before and after the detach, so a
 * case where the name was never suppressed at all cannot pass by accident.
 *
 * The cases are chosen so that a per-row identity check — "is THIS row owned by the
 * registry's organization" — fails at least one of them. That reading passed the first three
 * for a year's worth of plausible fixtures, because each has one package per name; clause 1
 * is an EXISTENCE question over `(type, name)` and the two only diverge when the
 * organization owns a second row of that name.
 */

use App\Enums\PackageType;
use App\Http\Controllers\Registry\PypiController;
use App\Http\Controllers\Registry\ResolvesRegistryPackage;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Python\PythonName;
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
function suppressesUpstreamVerbatim(PackageType $type, string $fullName, Group $group): bool
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

/** `PypiController::pythonExistsLocally()`, which is private on the controller. */
function suppressesUpstreamPython(string $normalized, Group $group): bool
{
    return (bool) (new ReflectionMethod(PypiController::class, 'pythonExistsLocally'))
        ->invoke(app(PypiController::class), $normalized, $group);
}

/**
 * Whichever of the two resolvers answers for this package's ecosystem. The console field is
 * type-agnostic, so the assertion has to be too — and PyPI is the half where the name the
 * resolver matches is not the string in the column.
 */
function suppressesUpstream(Package $package, Group $group): bool
{
    return $package->type === PackageType::Python
        ? suppressesUpstreamPython(PythonName::normalize($package->name), $group)
        : suppressesUpstreamVerbatim($package->type, $package->name, $group);
}

/** The `owned_by_registry_org` this registry's console page reports for this assignment. */
function reportedOwnership(TestCase $test, Group $group, Package $package): bool
{
    /** @var array{props: array{packages: array<int, array<string, mixed>>}} $page */
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
    expect(suppressesUpstream($package, $group))->toBeTrue();

    $group->packages()->detach($package->id);

    // Detached: still suppressed exactly when the console said the organization owns it.
    expect(suppressesUpstream($package, $group))->toBe($reported);
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

it('agrees with the resolver when the organization owns another package of the same name', function () {
    // The case a per-row identity check gets wrong, and it is reachable:
    // SharedAssignment::assertNameUnclaimedIn() reads assignedPackages(), so a LAPSED shared
    // assignment does not stop this organization creating its own package under that name.
    // The registry then carries both rows, and the lapsed one renders the "abgelaufen" note.
    $group = Group::factory()->create();
    $shared = Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['type' => 'composer', 'name' => 'acme/tools', 'shared' => true]);
    $group->packages()->attach($shared, ['available_until' => now()->subDay()]);

    // The own package of that name, which clause 1 matches on and which detaching the shared
    // assignment does nothing about. Not assigned, so nothing but ownership is in play.
    Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'acme/tools']);

    assertConsoleAgreesWithTheResolver($this, $group, $shared);
});

it('agrees with the resolver for Python names that differ only by normalisation', function () {
    // pythonExistsLocally() compares PEP 503-normalised names, so `Shared_Lib` and
    // `shared-lib` are one name to the resolver and two strings in the column. A lookup that
    // matched the stored name would call this row foreign and offer detaching as the release
    // path, while the resolver goes on suppressing through the own package.
    $group = Group::factory()->create();
    $shared = Package::factory()
        ->for(Organization::factory()->create(['is_operator' => true]))
        ->create(['type' => 'python', 'name' => 'shared-lib', 'shared' => true]);
    $group->packages()->attach($shared);

    Package::factory()->inOrgOf($group)->create(['type' => 'python', 'name' => 'Shared_Lib']);

    assertConsoleAgreesWithTheResolver($this, $group, $shared);
});
