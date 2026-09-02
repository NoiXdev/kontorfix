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
    $a = Package::factory()->inOrgOf($this->group)->create(['name' => 'acme/alpha', 'type' => 'composer']);
    $b = Package::factory()->inOrgOf($this->group)->create(['name' => 'beta/widget', 'type' => 'npm']);
    $this->group->packages()->attach([$a->id, $b->id]);
});

it('always sends the full portal package list — search/type filtering is client-side only', function () {
    // The controller used to pre-filter on bare `q`/`type` and echo them back in a
    // `filters` prop. That server-side filter was removed: it left a legacy
    // `?q=`/`?type=` bookmark silently narrowing the list with no way to see or
    // reset the filter from the UI (search/type now live entirely in useTableState,
    // prefix 'pkg', driven by `pkg_q`/`pkg_type`). The full list reaches the client
    // regardless of what a legacy URL carries, and there is no `filters` prop anymore.
    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?q=acme")
        ->assertInertia(fn ($p) => $p->has('packages', 2)->missing('filters'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?type=npm")
        ->assertInertia(fn ($p) => $p->has('packages', 2)->missing('filters'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}?q=acme&type=composer")
        ->assertInertia(fn ($p) => $p->has('packages', 2)->missing('filters'));

    $this->actingAs($this->member)->get("/portal/registries/{$this->group->id}")
        ->assertInertia(fn ($p) => $p->has('packages', 2)->missing('filters'));
});
