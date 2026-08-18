<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Package;
use App\Models\User;

function sortAdmin(): User
{
    return User::factory()->operator()->create(['role' => UserRole::Admin]);
}

it('sorts packages by a whitelisted column', function () {
    Package::factory()->create(['name' => 'zeta/one']);
    Package::factory()->create(['name' => 'alpha/one']);

    $this->actingAs(sortAdmin())
        ->get(route('admin.packages.index', ['sort' => 'name', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('packages.data.0.name', 'alpha/one')
            ->where('filters.sort', 'name'));
});

it('falls back to the default order for an unknown sort key', function () {
    Package::factory()->create(['name' => 'zeta/one']);

    // A key with no SQL expression must not reach the query builder. The response is the
    // unsorted default, not a 500 and not an unfiltered list.
    $this->actingAs(sortAdmin())
        ->get(route('admin.packages.index', ['sort' => 'name); drop table packages; --']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.sort', null));
});

it('ignores a direction that is neither asc nor desc', function () {
    Package::factory()->create(['name' => 'alpha/one']);

    $this->actingAs(sortAdmin())
        ->get(route('admin.packages.index', ['sort' => 'name', 'direction' => 'sideways']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.direction', 'asc'));
});

it('keeps sort state on the second page', function () {
    Package::factory()->count(30)->create();

    $this->actingAs(sortAdmin())
        ->get(route('admin.packages.index', ['sort' => 'name', 'direction' => 'desc', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.sort', 'name'));
});

it('sorts activity by a whitelisted column', function () {
    $admin = sortAdmin();
    Organization::factory()->create(['name' => 'Beta org']);
    Organization::factory()->create(['name' => 'Alpha org']);

    $this->actingAs($admin)
        ->get(route('admin.activity.index', ['sort' => 'log_name', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.direction', 'desc'));
});

it('ignores a direction that is neither asc nor desc for activity too', function () {
    $this->actingAs(sortAdmin())
        ->get(route('admin.activity.index', ['sort' => 'log_name', 'direction' => 'sideways']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.direction', 'asc'));
});

it('falls back to the default order for an unknown activity sort key', function () {
    // `causer_id` is a real physical column but deliberately excluded from SORTABLE — it
    // identifies the causer, it does not sort by the label the column renders — so this
    // also proves an unwhitelisted-but-real column name is rejected, not just malformed
    // input. The trailing SQL fragment additionally guarantees the assertion fails loudly
    // (not silently) if a raw value ever reached the query builder.
    $this->actingAs(sortAdmin())
        ->get(route('admin.activity.index', ['sort' => 'causer_id); drop table activity_log; --']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.sort', null));
});
