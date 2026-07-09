<?php

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
    $this->group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
});

it('adds a domain to the registry and shows it on the detail page', function () {
    $this->actingAs($this->admin)->post('/admin/domains', ['group_id' => $this->group->id, 'hostname' => 'packages.kadenz.test'])
        ->assertRedirect();

    $this->actingAs($this->admin)->get("/admin/groups/{$this->group->id}")
        ->assertInertia(fn ($p) => $p->where('domains', fn ($d) => collect($d)->pluck('hostname')->contains('packages.kadenz.test')));
});

it('adds an upstream to the registry and shows it on the detail page', function () {
    $this->actingAs($this->admin)->post('/admin/upstreams', [
        'group_id' => $this->group->id, 'type' => 'composer', 'url' => 'https://packagist.org', 'policy' => 'proxy',
    ])->assertRedirect();

    $this->actingAs($this->admin)->get("/admin/groups/{$this->group->id}")
        ->assertInertia(fn ($p) => $p->has('upstreams', 1));
});
