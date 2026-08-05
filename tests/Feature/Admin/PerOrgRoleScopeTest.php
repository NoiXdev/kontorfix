<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->orgA = Organization::factory()->create(['name' => 'Org A']);
    $this->orgB = Organization::factory()->create(['name' => 'Org B']);
    // Admin of Org A only — a per-organization role, not a global super-admin.
    $this->adminA = User::factory()->for($this->orgA)->create(['role' => UserRole::Admin]);
    $this->groupA = Group::factory()->for($this->orgA)->create(['name' => 'Reg A']);
    $this->groupB = Group::factory()->for($this->orgB)->create(['name' => 'Reg B']);
});

it('scopes the registry index to the admins own organization', function () {
    $this->actingAs($this->adminA)->get('/admin/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 1)->where('groups.0.name', 'Reg A'));
});

it('forbids a per-org admin from viewing another orgs registry', function () {
    $this->actingAs($this->adminA)->get("/admin/groups/{$this->groupB->id}")->assertForbidden();
    $this->actingAs($this->adminA)->get("/admin/groups/{$this->groupA->id}")->assertOk();
});

it('forbids updating or deleting another orgs registry', function () {
    $this->actingAs($this->adminA)
        ->put("/admin/groups/{$this->groupB->id}", ['name' => 'hacked', 'public' => false, 'portal_enabled' => true])
        ->assertForbidden();
    $this->actingAs($this->adminA)->delete("/admin/groups/{$this->groupB->id}")->assertForbidden();
    expect(Group::find($this->groupB->id))->not->toBeNull();
});

it('creates a registry in the admins own organization by default', function () {
    $this->actingAs($this->adminA)->post('/admin/groups', ['name' => 'New', 'slug' => 'new-a'])->assertRedirect();
    expect(Group::where('slug', 'new-a')->first()->organization_id)->toBe($this->orgA->id);
});

it('scopes package search to packages in the admins registries', function () {
    $mine = Package::factory()->create(['name' => 'a/mine']);
    $this->groupA->packages()->attach($mine->id);
    $theirs = Package::factory()->create(['name' => 'b/theirs']);
    $this->groupB->packages()->attach($theirs->id);

    $names = collect($this->actingAs($this->adminA)->getJson('/admin/package-search?q=')->json())->pluck('name');

    expect($names)->toContain('a/mine')->not->toContain('b/theirs');
});

it('forbids attaching a package to another orgs registry', function () {
    $pkg = Package::factory()->create(['name' => 'a/mine']);

    $this->actingAs($this->adminA)
        ->post("/admin/groups/{$this->groupB->id}/packages", ['package_ids' => [$pkg->id]])
        ->assertForbidden();
});

it('grants console access to an additional org via a membership role', function () {
    $home = Organization::factory()->create();
    $user = User::factory()->for($home)->create(['role' => UserRole::Member]);
    // Member at home, but admin of Org B through an explicit membership role.
    $user->organizations()->attach($this->orgB->id, ['role' => UserRole::Admin->value]);

    expect($user->administers($this->orgB->id))->toBeTrue()
        ->and($user->administers($this->orgA->id))->toBeFalse()
        ->and($user->canAdministerConsole())->toBeTrue();

    $this->actingAs($user)->get('/admin/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 1)->where('groups.0.name', 'Reg B'));
});

it('lets a super-admin see every organizations registries', function () {
    $super = User::factory()->for(Organization::factory())->create(['role' => UserRole::Member, 'is_super_admin' => true]);

    expect($super->isSuperAdmin())->toBeTrue();

    $this->actingAs($super)->get('/admin/groups')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('groups', 2));
});

it('filters everything to the selected scope and back to all', function () {
    $super = User::factory()->for(Organization::factory())->create(['is_super_admin' => true]);

    // Default: all organizations — both registries visible.
    $this->actingAs($super)->get('/admin/groups')->assertInertia(fn ($p) => $p->has('groups', 2));

    // Pin the scope to Org A.
    $this->actingAs($super)->post('/admin/scope', ['organization_id' => $this->orgA->id])->assertRedirect();
    $this->actingAs($super)->get('/admin/groups')
        ->assertInertia(fn ($p) => $p->has('groups', 1)->where('groups.0.name', 'Reg A'));

    // Reset to all.
    $this->actingAs($super)->post('/admin/scope', ['organization_id' => null])->assertRedirect();
    $this->actingAs($super)->get('/admin/groups')->assertInertia(fn ($p) => $p->has('groups', 2));
});

it('clamps the scope switch to organizations the user administers', function () {
    // adminA administers only Org A — an attempt to scope to Org B must be ignored, and
    // the console stays pinned to Org A (it can never widen access).
    $this->actingAs($this->adminA)->post('/admin/scope', ['organization_id' => $this->orgB->id])->assertRedirect();
    $this->actingAs($this->adminA)->get('/admin/groups')
        ->assertInertia(fn ($p) => $p->has('groups', 1)->where('groups.0.name', 'Reg A'));
});
