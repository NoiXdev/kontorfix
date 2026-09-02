<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('searches packages, registries and customers by name', function () {
    Package::factory()->create(['name' => 'acme/widget']);
    Group::factory()->for(Organization::factory())->create(['name' => 'Acme Registry']);
    Organization::factory()->create(['name' => 'Acme GmbH', 'is_operator' => false]);

    $res = $this->actingAs($this->admin)->getJson('/admin/search?q=acme');
    $res->assertOk();

    expect(collect($res->json('packages'))->pluck('name'))->toContain('acme/widget');
    expect(collect($res->json('registries'))->pluck('name'))->toContain('Acme Registry');
    expect(collect($res->json('customers'))->pluck('name'))->toContain('Acme GmbH');
});

it('is reachable by an org admin (scoped) but blocked for plain members', function () {
    // A plain member cannot reach the console search at all.
    $member = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member]);
    $this->actingAs($member)->getJson('/admin/search?q=x')->assertForbidden();

    // A customer-org admin may search, but only within their own scope and never sees
    // customer (organization) hits — nor packages from other organizations.
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    Package::factory()->create(['name' => 'other/secret']);
    Organization::factory()->create(['name' => 'Other GmbH', 'is_operator' => false]);

    $res = $this->actingAs($custAdmin)->getJson('/admin/search?q=other');
    $res->assertOk();
    expect($res->json('packages'))->toBe([]);
    expect($res->json('customers'))->toBe([]);
});

it('returns empty categories for a blank query', function () {
    $res = $this->actingAs($this->admin)->getJson('/admin/search?q=');
    $res->assertOk()->assertJson(['packages' => [], 'registries' => [], 'customers' => []]);
});

it('does not return customer results to a maintainer (customer detail is super-only)', function () {
    $maintainer = User::factory()->operator()->create(['role' => UserRole::Maintainer]);
    // A package attached to a registry in the maintainer's own organization is visible.
    $group = Group::factory()->for($maintainer->organization)->create();
    $pkg = Package::factory()->inOrgOf($group)->create(['name' => 'acme/widget']);
    $group->packages()->attach($pkg->id);
    Organization::factory()->create(['name' => 'Acme GmbH', 'is_operator' => false]);

    $res = $this->actingAs($maintainer)->getJson('/admin/search?q=acme');
    $res->assertOk();
    expect(collect($res->json('packages'))->pluck('name'))->toContain('acme/widget'); // Pakete: ja
    expect($res->json('customers'))->toBe([]); // Kunden: nein (nur Super-Admin)
});
