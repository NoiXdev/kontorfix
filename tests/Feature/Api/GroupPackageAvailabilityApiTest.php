<?php

/*
 * `GET|PUT /api/v1/groups/{group}/packages` against what the registry actually serves.
 *
 * The endpoint listed every pivot row with nothing to distinguish one the registry serves
 * from one whose `available_until` has passed — the same defect the admin console's
 * `in_force` was added to fix, and the same one the customer portal had. It is answered
 * differently here, and the difference is the point of this file:
 *
 *   the portal HIDES a lapsed assignment; this endpoint MARKS it.
 *
 * Because `update()` is a `sync()` — a detach by omission — the list a client GETs is the
 * list it sends back. Hiding a row would make the ordinary read-modify-write drop it, and
 * detaching a shared assignment is the one act that releases its name back to the public
 * index (spec §4 as amended: an explicit act opens the upstream fallthrough, the passage of
 * time does not). So the rows stay, and both fields the console shows travel with them.
 *
 * Both directions per field: a lapsed row is marked lapsed AND a live one is marked live,
 * so a resource that hard-coded either answer fails. The round-trip is asserted as a round
 * trip rather than as two reads, because "the list survives being sent back" is the property
 * the marking decision rests on.
 */

use App\Enums\ApiKeyPermission;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->org = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->create(['organization_id' => $this->org->id, 'role' => 'admin']);
    [, $this->plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
    $this->group = Group::factory()->create(['organization_id' => $this->org->id]);

    $this->live = Package::factory()->inOrgOf($this->group)->create(['name' => 'acme/live']);
    $this->lapsed = Package::factory()->inOrgOf($this->group)->create(['name' => 'zzz/lapsed']);

    $this->group->packages()->attach($this->live);
    $this->group->packages()->attach($this->lapsed, ['available_until' => now()->subDay()]);
});

it('says of each assignment whether the registry still serves it', function () {
    $this->withToken($this->plain)->getJson("/api/v1/groups/{$this->group->id}/packages")
        ->assertOk()
        // Ordered by name, so the live row is first and the lapsed one second.
        ->assertJsonPath('data.0.name', 'acme/live')
        ->assertJsonPath('data.0.in_force', true)
        ->assertJsonPath('data.0.available_until', null)
        ->assertJsonPath('data.1.name', 'zzz/lapsed')
        ->assertJsonPath('data.1.in_force', false)
        ->assertJsonPath('data.1.available_until', now()->subDay()->toIso8601String());
});

it('keeps listing a lapsed assignment, so a client cannot detach one by echoing the list back', function () {
    $listed = $this->withToken($this->plain)->getJson("/api/v1/groups/{$this->group->id}/packages")
        ->assertOk()
        ->json('data.*.id');

    expect($listed)->toContain($this->lapsed->id);

    $this->withToken($this->plain)->putJson("/api/v1/groups/{$this->group->id}/packages", [
        'package_ids' => $listed,
    ])->assertOk();

    // The row survived the round trip, and with its date intact — a `sync()` that re-created
    // it open-ended would also leave the count at two.
    expect($this->group->fresh()->packages()->count())->toBe(2)
        ->and($this->group->fresh()->assignedPackages()->count())->toBe(1);
});

it('marks an assignment in force again once its availability moves into the future', function () {
    $this->group->packages()->updateExistingPivot($this->lapsed->id, [
        'available_until' => now()->addDay(),
    ]);

    $this->withToken($this->plain)->getJson("/api/v1/groups/{$this->group->id}/packages")
        ->assertOk()
        ->assertJsonPath('data.1.in_force', true)
        ->assertJsonPath('data.1.available_until', now()->addDay()->toIso8601String());
});

it('marks the assignments it returns from a write, not only from a read', function () {
    // The PUT response is a second render of the same list, and it was a second call to the
    // same unmarked expression. A fix applied to index() alone would leave it unmarked.
    $this->withToken($this->plain)->putJson("/api/v1/groups/{$this->group->id}/packages", [
        'package_ids' => [$this->live->id, $this->lapsed->id],
    ])
        ->assertOk()
        ->assertJsonPath('data.0.in_force', true)
        ->assertJsonPath('data.1.in_force', false)
        ->assertJsonPath('data.1.available_until', now()->subDay()->toIso8601String());
});

it('leaves the assignment fields off a package that is not being read through a registry', function () {
    // They describe one package's place in ONE registry, and the same package sits in
    // several on different dates. Absent rather than null, so a client cannot read a
    // meaningless `in_force: false` off the package endpoint.
    $this->withToken($this->plain)->getJson("/api/v1/packages/{$this->lapsed->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.in_force')
        ->assertJsonMissingPath('data.available_until');
});
