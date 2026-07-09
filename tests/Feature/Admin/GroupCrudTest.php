<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Package;
use App\Models\User;

it('lists groups for admins', function () {
    Group::factory()->count(2)->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->get('/admin/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/groups/Index')->has('groups', 2));
});

it('creates a group with slug and assigns existing pool packages', function () {
    $pkgs = Package::factory()->count(2)->create();
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/groups', [
        'name' => 'Kadenz GmbH',
        'slug' => 'kadenz',
        'public' => false,
        'package_ids' => $pkgs->pluck('id')->all(),
    ])->assertRedirect();

    $group = Group::where('slug', 'kadenz')->firstOrFail();
    expect($group->packages)->toHaveCount(2)
        ->and($group->organization_id)->toBe($admin->organization_id);
});

it('rejects duplicate and malformed slugs', function () {
    Group::factory()->create(['slug' => 'kadenz']);
    $admin = User::factory()->operator()->create(['role' => UserRole::Admin]);

    $this->actingAs($admin)->post('/admin/groups', ['name' => 'X', 'slug' => 'kadenz'])
        ->assertSessionHasErrors('slug');
    $this->actingAs($admin)->post('/admin/groups', ['name' => 'X', 'slug' => 'Invalid Slug!'])
        ->assertSessionHasErrors('slug');
});

it('forbids members from managing groups', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Member]))
        ->get('/admin/groups')->assertForbidden();
});

it('deletes a group', function () {
    $group = Group::factory()->create();
    $this->actingAs(User::factory()->operator()->create(['role' => UserRole::Admin]))
        ->delete("/admin/groups/{$group->id}")->assertRedirect();
    expect(Group::find($group->id))->toBeNull();
});
