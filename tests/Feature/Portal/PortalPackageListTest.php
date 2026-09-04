<?php

/*
 * The portal's landing page: what /c/{orgSlug} puts in front of the customer.
 *
 * PortalPackagesTest owns the composition rules (what belongs in the set, in what order,
 * whose packages, which registries). This file owns only what the PAGE receives — the payload
 * the Vue component renders from — and in particular the two answers that can differ on one
 * package: the row's `in_force` and each registry entry's own.
 */

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;

it('shows an own package and a lapsed shared one, the second marked', function () {
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $own = Package::factory()->for($org)->create(['name' => 'acme/own']);
    // Two ecosystems, so `type` is observable in both directions: it drives the type filter
    // and the label column, and a single type in the fixture cannot tell the real field from
    // a constant.
    $shared = Package::factory()->for($operator)->create([
        'name' => 'acme/shared',
        'type' => PackageType::Npm,
        'shared' => true,
    ]);
    $group->packages()->attach($own->id);
    $group->packages()->attach($shared->id, ['available_until' => now()->subDay()]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->component('portal/Packages')
            ->where('packages.0.name', 'acme/own')
            // The customer's OWN package is not marked `geteilt`. Asserted because the
            // positive case alone cannot distinguish the flag from a constant: sending
            // `true` for every row left this whole directory green while the portal told a
            // customer that each of their own packages was shared with them.
            ->where('packages.0.shared', false)
            ->where('packages.0.type', 'composer')
            // PackageFactory creates no versions, so this row HAS no release — and the
            // negative case is the only one that separates the field from a constant. Every
            // other row in this file is version-less and unasserted, which let both a literal
            // and `$row['in_force'] ? … : null` pass the whole suite while every package on
            // the landing page advertised a version it does not have.
            ->where('packages.0.latest_version', null)
            ->where('packages.0.in_force', true)
            ->where('packages.1.name', 'acme/shared')
            ->where('packages.1.shared', true)
            ->where('packages.1.type', 'npm')
            ->where('packages.1.in_force', false));
});

it('marks the registry that stopped serving a package the customer still has elsewhere', function () {
    // The case the row flag alone cannot describe. `live` still serves the package, so the
    // row is in force and carries no lapsed badge — correctly, the package IS usable. Only
    // the entry for `lapsed` can tell the customer that the link to THAT registry leads to a
    // 404, and a page that rendered the row state alone would report all clear over it.
    $operator = Organization::factory()->create(['is_operator' => true]);
    $org = Organization::factory()->create(['slug' => 'acme']);
    $live = Group::factory()->for($org)->create(['name' => 'aa-live']);
    $lapsed = Group::factory()->for($org)->create(['name' => 'zz-lapsed']);
    $shared = Package::factory()->for($operator)->create(['name' => 'acme/shared', 'shared' => true]);
    $live->packages()->attach($shared->id);
    $lapsed->packages()->attach($shared->id, ['available_until' => now()->subDay()]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('packages.0.in_force', true)
            ->where('packages.0.registries.0.name', 'aa-live')
            ->where('packages.0.registries.0.in_force', true)
            ->where('packages.0.registries.1.name', 'zz-lapsed')
            ->where('packages.0.registries.1.in_force', false)
            // The DAY, on the entry that lapsed. Every other assertion of `available_until`
            // in this file is on an in-force entry, and that one-sidedness is what let
            // `$entry->in_force ? $entry->available_until?->toDateString() : null` pass the
            // whole suite — silently degrading every marker from "abgelaufen am 31.08.2026"
            // to a bare "abgelaufen", which is exactly the half spec §3 asks for.
            ->where('packages.0.registries.1.available_until', now()->subDay()->toDateString()));
});

it('sends each registry its own end date, not the one it was listed beside', function () {
    // Both assignments have a date and the dates differ, so an entry that read the date off
    // the package model's pivot — the pivot of whichever registry was seen first — would
    // answer with a real, plausible, wrong day on one of the two rows.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $first = Group::factory()->for($org)->create(['name' => 'aa-first']);
    $second = Group::factory()->for($org)->create(['name' => 'zz-second']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $first->packages()->attach($package->id, ['available_until' => '2026-12-31']);
    $second->packages()->attach($package->id, ['available_until' => '2027-06-30']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('packages.0.registries.0.available_until', '2026-12-31')
            ->where('packages.0.registries.1.available_until', '2027-06-30'));
});

it('leaves the registries of a package out when the portal hides them', function () {
    // Not a repeat of PortalPackagesTest's own coverage of the filter: this asserts that the
    // PAGE never renders a link the customer cannot follow. Every registry in the payload is
    // linked, and GroupPolicy::view() answers 403 for a registry the portal hides.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $hidden = Group::factory()->for($org)->create(['name' => 'hidden', 'portal_enabled' => false]);
    $visible = Group::factory()->for($org)->create(['name' => 'visible']);
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    $visible->packages()->attach($package->id);
    $hidden->packages()->attach($package->id);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->count('packages.0.registries', 1)
            ->where('packages.0.registries.0.name', 'visible'));
});

it('names the newest version of the package', function () {
    // Spec §3 asks the landing page for the current version beside the type. Two releases,
    // the older one created LAST, so a payload that simply took the first row of the relation
    // in insertion order would answer `v1.0.0` — the ordering is part of the claim.
    $org = Organization::factory()->create(['slug' => 'acme']);
    $group = Group::factory()->for($org)->create();
    $package = Package::factory()->for($org)->create(['name' => 'acme/tools']);
    PackageVersion::factory()->for($package)->create(['version' => '2.1.0.0', 'version_pretty' => 'v2.1.0', 'released_at' => now()]);
    PackageVersion::factory()->for($package)->create(['version' => '1.0.0.0', 'version_pretty' => 'v1.0.0', 'released_at' => now()->subYear()]);
    $group->packages()->attach($package->id);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('packages.0.latest_version', 'v2.1.0'));
});
