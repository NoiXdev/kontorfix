<?php

/*
 * Task 9: the portal's own reading of a licence-bounded assignment — the highest version the
 * customer's own bounds admit, and whether that leaves a newer release withheld. This is the
 * upsell surface the enforcement (Tasks 4-6) exists to make legible: a package manager
 * offered a version it cannot download aborts the install, so the registry HIDES an
 * out-of-licence version rather than refusing it, and the portal is where the customer can
 * find out that "nothing changed" and a build stalled anyway.
 *
 * The privacy rule this file exists to enforce, first, because it was the Critical in the
 * previous task: the note is about THIS customer's own assignment only — the highest version
 * THEIR OWN bounds admit. Built from the one assignment row PortalPackages already reads for
 * this organization, never from another organization's bounds on the same shared package.
 */

use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\Portal\PortalPackages;

it('names the highest version a bounded licence admits when a newer release is withheld', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/bounded']);
    $versions = ['1.9.0' => 3, '2.0.0' => 2, '2.4.1' => 1, '3.0.0' => 0];
    foreach ($versions as $v => $daysAgo) {
        PackageVersion::factory()->for($package)->create([
            'version' => $v, 'version_pretty' => "v{$v}", 'released_at' => now()->subDays($daysAgo),
        ]);
    }
    $group->packages()->attach($package->id, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $row = app(PortalPackages::class)->for($org)->first();
    $licence = $row['groups']->first()->licence;

    expect($licence)->not->toBeNull()
        ->and($licence->highest_permitted)->toBe('v2.4.1')
        ->and($licence->withheld)->toBeTrue();
});

it('reports no licence note for an unbounded assignment', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/open']);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0', 'version_pretty' => 'v1.0.0']);
    $group->packages()->attach($package->id);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->first()->licence)->toBeNull();
});

it('reports withheld as false when the bounds already admit the newest release', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/covered']);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0', 'version_pretty' => 'v1.0.0', 'released_at' => now()->subDay()]);
    PackageVersion::factory()->for($package)->create(['version' => '2.4.1', 'version_pretty' => 'v2.4.1', 'released_at' => now()]);
    $group->packages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '5.0.0']);

    $row = app(PortalPackages::class)->for($org)->first();
    $licence = $row['groups']->first()->licence;

    expect($licence)->not->toBeNull()
        ->and($licence->highest_permitted)->toBe('v2.4.1')
        ->and($licence->withheld)->toBeFalse();
});

it('never lets one organization\'s licence note carry another organization\'s bounds', function () {
    // The same shared package, assigned into two different organizations' registries with
    // two DIFFERENT windows. If org A's note ever leaked across the tenancy boundary, this
    // is the value it would leak: org B's narrower window admits only v1.0.0/v2.0.0, org A's
    // admits up to (not including) v3.0.0.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    foreach (['1.0.0' => 3, '2.0.0' => 2, '2.4.1' => 1, '3.0.0' => 0] as $v => $daysAgo) {
        PackageVersion::factory()->for($shared)->create([
            'version' => $v, 'version_pretty' => "v{$v}", 'released_at' => now()->subDays($daysAgo),
        ]);
    }

    $orgA = Organization::factory()->create();
    $groupA = Group::factory()->for($orgA)->create();
    $groupA->packages()->attach($shared->id, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);

    $orgB = Organization::factory()->create();
    $groupB = Group::factory()->for($orgB)->create();
    $groupB->packages()->attach($shared->id, ['version_min' => '1.0.0', 'version_max' => '2.0.0']);

    $rowA = app(PortalPackages::class)->for($orgA)->first();

    // Only org A's own registry appears at all — org B's group and its window are not in
    // this payload in any form.
    expect($rowA['groups'])->toHaveCount(1)
        ->and($rowA['groups']->pluck('group.id')->all())->toBe([$groupA->id]);

    $licenceA = $rowA['groups']->first()->licence;

    expect($licenceA->highest_permitted)->toBe('v2.4.1')
        ->and($licenceA->withheld)->toBeTrue();
});

it('never asks the licence question for a Docker package, whose bounds are always absent', function () {
    // AssignmentWriter refuses to ever persist bounds for a Docker package; this attaches
    // the pivot row directly, bypassing that guard, the same "a bad row can exist" scenario
    // VersionEntitlement's own docblock tolerates for an unparseable PyPI bound. If the
    // licence note ever asked VersionEntitlement::permits() for a Docker package it would
    // throw LogicException instead of quietly reporting nothing.
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->docker()->create(['name' => 'acme/image']);
    $group->packages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '2.0.0']);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->first()->licence)->toBeNull();
});

it('reports nothing for a bounded assignment on a package with no releases yet', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/unreleased']);
    $group->packages()->attach($package->id, ['version_min' => '1.0.0', 'version_max' => '2.0.0']);

    $row = app(PortalPackages::class)->for($org)->first();

    expect($row['groups']->first()->licence)->toBeNull();
});

it('reports withheld with no version to name when the bounds admit none of the published releases', function () {
    $org = Organization::factory()->create();
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/ahead']);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0', 'version_pretty' => 'v1.0.0']);
    $group->packages()->attach($package->id, ['version_min' => '5.0.0', 'version_max' => '6.0.0']);

    $row = app(PortalPackages::class)->for($org)->first();
    $licence = $row['groups']->first()->licence;

    expect($licence)->not->toBeNull()
        ->and($licence->highest_permitted)->toBeNull()
        ->and($licence->withheld)->toBeTrue();
});

it('sends the licence note through to the page the customer sees', function () {
    // End-to-end, through Portal\PackageController::index() and into the Inertia payload —
    // the level PortalPackages' own unit-shaped cases above cannot reach, and the level
    // Packages.vue actually reads.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/bounded']);
    PackageVersion::factory()->for($package)->create(['version' => '2.4.1', 'version_pretty' => 'v2.4.1', 'released_at' => now()->subDay()]);
    PackageVersion::factory()->for($package)->create(['version' => '3.0.0', 'version_pretty' => 'v3.0.0', 'released_at' => now()]);
    $group->packages()->attach($package->id, ['version_min' => '2.0.0', 'version_max' => '3.0.0']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('packages.0.registries.0.licence.highest_permitted', 'v2.4.1')
            ->where('packages.0.registries.0.licence.withheld', true));
});
