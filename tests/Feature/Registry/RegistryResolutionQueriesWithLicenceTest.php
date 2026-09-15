<?php

// A regression guard for the same axis RegistryResolutionQueriesTest.php pins — the number
// of queries against the `organizations` table on the registry metadata hot path — but with
// a fixture RegistryResolutionQueriesTest.php never carries: a LIVE org licence
// (`organization_package`) alongside the registry's own `group_package` assignment. This
// axis has regressed twice during this branch, both times a `BelongsToMany`-through-pivot
// relation (`Package::licensedOrganizations()` / `Organization::licensedPackages()`) adding
// a join to `organizations` on this path (see RegistryAccessService::availablePackages()'s
// own docblock, which names the exact incident and fix). Both times the existing test caught
// it — but only because its fixture has no licence row at all, so a future change that is
// safe with no licence and only breaks once one exists would sail through unnoticed. This
// file exists to close that gap, deliberately kept separate from
// RegistryResolutionQueriesTest.php rather than folded into it.

use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors RegistryResolutionQueriesTest.php's own sqlOf() exactly, under a different name —
 * both files load in the same test run, and PHP does not allow two global functions of the
 * same name to be declared.
 *
 * @return list<string>
 */
function sqlOfWithLicenceFixture(callable $work): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $work();

    return $queries;
}

it('still resolves one organizations-table query when a live org licence sits alongside a registry assignment', function () {
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $organization = $group->organization;
    $package = Package::factory()->inOrgOf($group)->create([
        'type' => 'composer',
        'name' => 'acme/tools',
        'shared' => true,
    ]);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0']);

    // The org-wide ceiling: live, unexpired, wide enough to admit the version above.
    $organization->licensedPackages()->attach($package->id, [
        'version_min' => '1.0.0',
        'version_max' => '5.0.0',
        'available_until' => now()->addYear(),
    ]);

    // The per-registry assignment the licence narrows but does not remove — the ordinary
    // case a registry with BOTH a licence and an assignment is in.
    $group->packages()->attach($package, [
        'version_min' => '1.0.0',
        'version_max' => '2.0.0',
    ]);

    $url = registryPath($group).'/p2/acme/tools.json';

    $sql = sqlOfWithLicenceFixture(fn () => $this->getJson($url)->assertOk());

    $organizationLookups = array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'from "organizations"')));

    // Exactly one: the seek that turns {orgSlug} into the organization during resolution.
    // Neither the registry's own availability check (RegistryAccessService::availablePackages())
    // nor the licence read (VersionEntitlement::organizationLicence()) may add a second one —
    // both read `organization_package` directly rather than through the BelongsToMany
    // relations to `organizations`.
    expect($organizationLookups)->toHaveCount(1);
});
