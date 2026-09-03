<?php

// The registry admin screens eager-load the organization with an explicit column list, and
// the registry URL is built from its slug (RegistryUrl::path). Eloquent strict mode is off
// repo-wide, so a column list that omits `slug` yields null instead of raising — the URL
// then comes out as /r//{groupSlug} with the whole suite green. This pins the column list
// itself, because nothing else would notice.

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return list<string>
 */
function organizationSelectsDuring(callable $work): array
{
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'from "organizations"')) {
            $queries[] = $query->sql;
        }
    });

    $work();

    return $queries;
}

it('loads the organization slug for the registry list', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    Group::factory()->create(['organization_id' => $admin->organization_id]);

    $selects = organizationSelectsDuring(fn () => $this->actingAs($admin)->get('/admin/groups')->assertOk());

    $eagerLoads = array_values(array_filter($selects, fn (string $q): bool => str_contains($q, 'where "organizations"."id" in')));

    expect($eagerLoads)->not->toBeEmpty()
        ->and($eagerLoads[0])->toContain('"slug"');
});

it('loads the organization slug for a single registry', function () {
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $group = Group::factory()->create(['organization_id' => $admin->organization_id]);

    $selects = organizationSelectsDuring(fn () => $this->actingAs($admin)->get("/admin/groups/{$group->id}")->assertOk());

    $eagerLoads = array_values(array_filter($selects, fn (string $q): bool => str_contains($q, 'where "organizations"."id" in')));

    expect($eagerLoads)->not->toBeEmpty()
        ->and($eagerLoads[0])->toContain('"slug"');
});
