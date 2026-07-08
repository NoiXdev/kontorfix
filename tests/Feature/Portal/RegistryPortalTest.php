<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->orgA = Organization::factory()->create();
    $this->member = User::factory()->for($this->orgA)->create(['role' => UserRole::Member]);
});

it('lists only the members own registries', function () {
    $mine = Group::factory()->for($this->orgA)->create(['name' => 'Acme Registry', 'slug' => 'acme']);
    Group::factory()->for(Organization::factory()->create())->create(['name' => 'Foreign']);

    $this->actingAs($this->member)->get('/portal')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registries')
            ->has('registries', 1)
            ->where('registries.0.name', 'Acme Registry'));
});

it('shows a registry with setup snippets and its packages', function () {
    $group = Group::factory()->for($this->orgA)->create(['slug' => 'acme']);
    $pkg = Package::factory()->create(['name' => 'acme/widget']);
    $group->packages()->attach($pkg);

    $this->actingAs($this->member)->get("/portal/registries/{$group->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('portal/Registry')
            ->where('registry.slug', 'acme')
            ->where('snippets.composer', fn ($v) => str_contains($v, '/r/acme'))
            ->has('packages', 1)
            ->where('packages.0.name', 'acme/widget'));
});

it('forbids viewing a foreign registry', function () {
    $foreign = Group::factory()->for(Organization::factory()->create())->create();

    $this->actingAs($this->member)->get("/portal/registries/{$foreign->id}")->assertForbidden();
});

it('redirects guests to login', function () {
    $group = Group::factory()->for($this->orgA)->create();
    $this->get("/portal/registries/{$group->id}")->assertRedirect('/login');
});
