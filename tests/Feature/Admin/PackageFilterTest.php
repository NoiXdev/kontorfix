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
    $a = Package::factory()->inOrgOf($g)->create(['name' => 'acme/alpha', 'type' => 'composer', 'sync_status' => SyncStatus::Synced]);
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

it('answers a malformed group filter with an empty list instead of a driver error', function () {
    // `group` is a plain query-string value interpolated into a Postgres `uuid`
    // comparison. Anything that is not a UUID raised SQLSTATE[22P02], which nothing
    // renders — a 500 plus a stack trace, with the SQL and its bound parameters,
    // appended to an unrotated laravel.log for every request, on a route with no
    // throttle.
    $g = Group::factory()->for(Organization::factory())->create();
    $a = Package::factory()->inOrgOf($g)->create(['name' => 'acme/alpha']);
    $g->packages()->attach($a);

    // Reachability anchor: the same route with a well-formed id reaches the controller,
    // renders the Inertia page and applies the filter — so the assertion below is about
    // the guard on that filter, not about an earlier refusal.
    $this->actingAs($this->admin)->get("/admin/packages?group={$g->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Index')->has('packages.data', 1));

    $this->actingAs($this->admin)->get('/admin/packages?group=not-a-uuid')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/packages/Index')->has('packages.data', 0));

    // 36 dashes: the degenerate value that satisfied the `[0-9a-fA-F-]{36}` shape this
    // branch reached for twice before.
    $this->actingAs($this->admin)->get('/admin/packages?group='.str_repeat('-', 36))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('packages.data', 0));
});
