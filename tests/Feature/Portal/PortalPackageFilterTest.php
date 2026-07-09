<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    config(['app.url' => 'https://reg.example.test']);
    $this->org = Organization::factory()->create();
    $this->member = User::factory()->for($this->org)->create(['role' => UserRole::Member]);
    $this->group = Group::factory()->for($this->org)->create(['slug' => 'acme']);
    $a = Package::factory()->create(['name' => 'acme/alpha', 'type' => 'composer']);
    $b = Package::factory()->create(['name' => 'beta/widget', 'type' => 'npm']);
    $this->group->packages()->attach([$a->id, $b->id]);
});

it('filters the portal package list by name and type', function () {
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?q=acme")
        ->assertInertia(fn ($p) => $p->has('packages', 1)->where('packages.0.name', 'acme/alpha'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?type=npm")
        ->assertInertia(fn ($p) => $p->has('packages', 1)->where('packages.0.name', 'beta/widget'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?q=acme&type=composer")
        ->assertInertia(fn ($p) => $p->where('filters.q', 'acme')->where('filters.type', 'composer'));
});
