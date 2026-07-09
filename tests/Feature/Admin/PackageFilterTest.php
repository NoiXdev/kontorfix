<?php

use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->operator()->create(['role' => UserRole::Admin]);
});

it('filters packages by name, type, status and group', function () {
    $g = Group::factory()->for(Organization::factory())->create();
    $a = Package::factory()->create(['name' => 'acme/alpha', 'type' => 'composer', 'sync_status' => SyncStatus::Synced]);
    $b = Package::factory()->create(['name' => 'beta/widget', 'type' => 'npm', 'sync_status' => SyncStatus::Failed]);
    $g->packages()->attach($a);

    $this->actingAs($this->admin)->get('/admin/packages?q=acme')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'acme/alpha'));

    $this->actingAs($this->admin)->get('/admin/packages?type=npm')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'beta/widget'));

    $this->actingAs($this->admin)->get('/admin/packages?status=failed')
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'beta/widget'));

    $this->actingAs($this->admin)->get("/admin/packages?group={$g->id}")
        ->assertInertia(fn ($p) => $p->has('packages.data', 1)->where('packages.data.0.name', 'acme/alpha'));

    $this->actingAs($this->admin)->get('/admin/packages?q=acme&type=composer')
        ->assertInertia(fn ($p) => $p->where('filters.q', 'acme')->where('filters.type', 'composer'));
});
