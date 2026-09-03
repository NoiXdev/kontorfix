<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('updates a registry name, visibility and slug', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Alt', 'slug' => 'kadenz', 'public' => false]);

    $this->actingAs($this->admin)->put("/admin/groups/{$group->id}", ['name' => 'Neu', 'public' => true, 'slug' => 'umbenannt'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $fresh = $group->fresh();
    expect($fresh->name)->toBe('Neu')->and($fresh->public)->toBeTrue()
        ->and($fresh->slug)->toBe('umbenannt');
});

it('leaves the slug alone when the request does not carry one', function () {
    $group = Group::factory()->for(Organization::factory())->create(['name' => 'Alt', 'slug' => 'kadenz']);

    $this->actingAs($this->admin)->put("/admin/groups/{$group->id}", ['name' => 'Neu'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($group->fresh()->slug)->toBe('kadenz');
});

it('requires a name', function () {
    $group = Group::factory()->for(Organization::factory())->create();
    $this->actingAs($this->admin)->put("/admin/groups/{$group->id}", ['name' => ''])->assertSessionHasErrors('name');
});

it('is operator-gated', function () {
    $group = Group::factory()->for(Organization::factory())->create();
    $custAdmin = User::factory()->for(Organization::factory()->create(['is_operator' => false]))->create(['role' => UserRole::Admin]);
    $this->actingAs($custAdmin)->put("/admin/groups/{$group->id}", ['name' => 'X'])->assertForbidden();
});
