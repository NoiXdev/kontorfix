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

it('is operator-gated', function () {
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->getJson('/admin/search?q=x')->assertForbidden();
});

it('returns empty categories for a blank query', function () {
    $res = $this->actingAs($this->admin)->getJson('/admin/search?q=');
    $res->assertOk()->assertJson(['packages' => [], 'registries' => [], 'customers' => []]);
});

it('does not return customer results to a maintainer (customer detail is admin-only)', function () {
    $maintainer = User::factory()->operator()->create(['role' => UserRole::Maintainer]);
    Package::factory()->create(['name' => 'acme/widget']);
    Organization::factory()->create(['name' => 'Acme GmbH', 'is_operator' => false]);

    $res = $this->actingAs($maintainer)->getJson('/admin/search?q=acme');
    $res->assertOk();
    expect(collect($res->json('packages'))->pluck('name'))->toContain('acme/widget'); // Pakete: ja
    expect($res->json('customers'))->toBe([]); // Kunden: nein
});
