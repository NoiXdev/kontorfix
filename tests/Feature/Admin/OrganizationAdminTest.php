<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->operator = Organization::factory()->create(['is_operator' => true]);
    $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
});

it('lists organizations with counts', function () {
    $cust = Organization::factory()->create(['name' => 'Kadenz GmbH']);
    User::factory()->for($cust)->create(['role' => UserRole::Member]);
    Group::factory()->for($cust)->create();

    $this->actingAs($this->admin)->get('/admin/organizations')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/organizations/Index')
            ->has('organizations', 2)
            ->where('organizations', fn ($orgs) => collect($orgs)->firstWhere('name', 'Kadenz GmbH')['users_count'] === 1));
});

it('forbids maintainers', function () {
    $m = User::factory()->for(Organization::factory())->create(['role' => UserRole::Maintainer]);
    $this->actingAs($m)->get('/admin/organizations')->assertForbidden();
});

it('creates a customer organization (never an operator org from the gui)', function () {
    $this->actingAs($this->admin)->post('/admin/organizations', ['name' => 'Neu GmbH', 'slug' => 'neu'])
        ->assertRedirect();
    $org = Organization::where('slug', 'neu')->first();
    expect($org)->not->toBeNull()->and($org->is_operator)->toBeFalse();
});

it('shows a vendor detail page with registries, users and tokens', function () {
    $cust = Organization::factory()->create(['name' => 'Kadenz GmbH']);
    Group::factory()->for($cust)->create(['name' => 'Kadenz Registry']);
    User::factory()->for($cust)->create();

    $this->actingAs($this->admin)->get("/admin/organizations/{$cust->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/organizations/Show')
            ->where('organization.name', 'Kadenz GmbH')->has('registries', 1)->has('users', 1)->has('tokens'));
});

it('refuses to delete the operator org, deletes an empty customer org', function () {
    $this->actingAs($this->admin)->delete("/admin/organizations/{$this->operator->id}")->assertSessionHasErrors();
    expect(Organization::find($this->operator->id))->not->toBeNull();

    $empty = Organization::factory()->create();
    $this->actingAs($this->admin)->delete("/admin/organizations/{$empty->id}")->assertRedirect();
    expect(Organization::find($empty->id))->toBeNull();
});

it('refuses to delete a customer org that still has users or registries', function () {
    $cust = Organization::factory()->create();
    Group::factory()->for($cust)->create();
    $this->actingAs($this->admin)->delete("/admin/organizations/{$cust->id}")->assertSessionHasErrors();
    expect(Organization::find($cust->id))->not->toBeNull();
});
