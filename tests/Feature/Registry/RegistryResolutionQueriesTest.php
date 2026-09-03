<?php

// How the registry is resolved, not just what it resolves to. Registry resolution runs on
// every metadata and artifact request — one `composer install` fires hundreds — so the
// shape of these two queries is part of the behaviour, and nothing else in the suite would
// notice it degrading.

use App\Models\Group;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function sqlOf(callable $work): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $work();

    return $queries;
}

it('resolves the registry through an index-seekable lookup, not a scan over every registry', function () {
    // The index for this lookup is (organization_id, slug); the only other one that could
    // serve it is the plain `slug` index 2026_09_03_100100 adds for the bare-slug namespace
    // checks, and that one has nothing to say about the organization. Postgres cannot seek
    // a composite index on its second column, so a lookup that filters on `slug` and carries
    // the organization as a correlated EXISTS is a sequential scan over every registry on
    // the instance. Measured on 400 registries before this was fixed: "Seq Scan on groups
    // … Rows Removed by Filter: 200". Resolving the organization first turns both halves
    // into index seeks (organizations_slug_unique, then groups_organization_id_slug_unique
    // on BOTH columns).
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $url = registryPath($group).'/packages.json';

    $sql = sqlOf(fn () => $this->get($url)->assertOk());

    $groupLookups = array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'from "groups"')));

    expect($groupLookups)->not->toBeEmpty();

    foreach ($groupLookups as $query) {
        // A correlated subquery on the slug is exactly the shape that cannot use the index.
        expect($query)->not->toContain('exists (select');
    }

    // The leading column of the index has to be in the predicate, or there is no seek.
    expect($groupLookups[0])->toContain('"organization_id" =');
});

it('does not re-fetch the organization the resolution already had', function () {
    // RegistryUrl::path() reads $group->organization, and it is called for the base URL and
    // the metadata-url prefix of every response. Without the relation being set from the
    // row the middleware already loaded, each of those lazy-loads the organization again —
    // a select for a row the resolution held and threw away.
    $group = Group::factory()->create(['slug' => 'packages', 'public' => true]);
    $package = Package::factory()->inOrgOf($group)->create(['type' => 'composer', 'name' => 'acme/tools']);
    PackageVersion::factory()->for($package)->create();
    $group->packages()->attach($package);
    $url = registryPath($group).'/p2/acme/tools.json';

    $sql = sqlOf(fn () => $this->getJson($url)->assertOk());

    $organizationLookups = array_values(array_filter($sql, fn (string $q): bool => str_contains($q, 'from "organizations"')));

    // Exactly one: the seek that turns {orgSlug} into the organization. Everything after
    // that — the registry-type gate, the URL builder — reads the object it produced.
    expect($organizationLookups)->toHaveCount(1);
});
