<?php

use App\Enums\UserRole;
use App\Models\Package;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

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

    // Two rows whose `log_name` order (descending) is the OPPOSITE of the default
    // `latest('id')` order, so a test that only checked the echoed filters — or that
    // happened to agree with the fallback order by coincidence — could not pass here.
    // 'zebra' is created first (lower id), 'alpha' second (higher id):
    //   - default (id desc, most recent first):      alpha, zebra
    //   - sort=log_name&direction=desc (z before a):  zebra, alpha
    // The two orders disagree on which row comes first, so asserting the first row is
    // 'zebra' fails outright under the fallback. Neither name collides with the
    // auto-logged `log_name`s this suite produces ('user', 'organization', 'registry',
    // 'package' — all alphabetically below 'zebra'), so it stays the true first row
    // under the real sort too.
    Activity::create(['log_name' => 'zebra', 'description' => 'first']);
    Activity::create(['log_name' => 'alpha', 'description' => 'second']);

    $this->actingAs($admin)
        ->get(route('admin.activity.index', ['sort' => 'log_name', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data.0.log_name', 'zebra')
            ->where('filters.sort', 'log_name')
            ->where('filters.direction', 'desc'));
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

/**
 * Two rows in one log, inserted oldest first so that id order and created_at order agree —
 * the shape an append-only audit log actually has. `log=seed` scopes every request below to
 * them, so the activity the admin fixture writes for itself cannot take the first row.
 */
function seedTwoActivities(): void
{
    Activity::create(['log_name' => 'seed', 'description' => 'older', 'created_at' => now()->subDays(2)]);
    Activity::create(['log_name' => 'seed', 'description' => 'newer', 'created_at' => now()->subDay()]);
}

it('reports the direction the unsorted default actually orders by', function () {
    seedTwoActivities();

    // The default order is `latest('id')` — newest first — while `direction` defaults to
    // `asc` because that is the raw parameter default. The timeline's direction toggle
    // renders this value, so the old payload had it offering "Älteste zuerst" over a
    // newest-first list. `direction=asc` is passed here without a `sort`, which is exactly
    // the case where the requested direction is not the one applied.
    $this->actingAs(sortAdmin())
        ->get(route('admin.activity.index', ['log' => 'seed', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data.0.description', 'newer')
            ->where('filters.direction', 'desc'));
});

it('orders the activity timeline oldest first when the direction toggle asks for it', function () {
    seedTwoActivities();

    // These are the exact two parameters the toggle writes into the query string; the
    // Vitest cover for `useActivityQuery` asserts it emits this pair and nothing else.
    $this->actingAs(sortAdmin())
        ->get(route('admin.activity.index', ['log' => 'seed', 'sort' => 'created_at', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data.0.description', 'older')
            ->where('filters.sort', 'created_at')
            ->where('filters.direction', 'asc'));
});

it('orders the activity timeline newest first when the toggle flips back', function () {
    seedTwoActivities();

    $this->actingAs(sortAdmin())
        ->get(route('admin.activity.index', ['log' => 'seed', 'sort' => 'created_at', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activities.data.0.description', 'newer')
            ->where('filters.sort', 'created_at')
            ->where('filters.direction', 'desc'));
});
