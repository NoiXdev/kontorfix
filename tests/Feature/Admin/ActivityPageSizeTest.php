<?php

use App\Enums\UserRole;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

function pageSizeAdmin(): User
{
    return User::factory()->operator()->create(['role' => UserRole::Admin]);
}

/**
 * Enough rows that every offered size is smaller than the total. A seed of 30 would make
 * `per_page=100` and `per_page=50` return the same 30 rows, and the row-count assertions
 * below — the only ones that prove the number reaches the query rather than only the
 * payload — would then pass for a controller that ignores the size entirely.
 *
 * Seed AFTER the admin fixture: creating the user and its operator org writes activity of
 * its own, and the default order is `latest('id')`, so seeding first would push those rows
 * onto the front page and make the slice assertions depend on how many the fixture logs.
 */
function seedActivity(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        Activity::create(['log_name' => 'seed', 'description' => "entry {$i}"]);
    }
}

it('paginates activity at fifty by default', function () {
    $admin = pageSizeAdmin();
    seedActivity(120);

    $this->actingAs($admin)
        ->get(route('admin.activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.per_page', 50)
            // The echoed filter alone would also be satisfied by a controller that reports
            // a size it never paginates by, so the page itself is counted.
            ->has('activities.data', 50));
});

it('honours each offered page size', function (int $size) {
    $admin = pageSizeAdmin();
    seedActivity(120);

    $this->actingAs($admin)
        ->get(route('admin.activity.index', ['per_page' => $size]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.per_page', $size)
            ->has('activities.data', $size));
})->with([25, 50, 100]);

it('falls back to fifty for a size that is not offered', function (mixed $requested) {
    $admin = pageSizeAdmin();
    seedActivity(120);

    // A size straight from the query string would let an unthrottled caller ask the
    // database for 100000 rows. The response is the default page — not an error, and not
    // the requested figure echoed back. `30` is the size this listing used to hardcode:
    // a real, sane number that is still not on the list, so it proves the check is a
    // whitelist rather than a range or a mere sanity guard.
    $this->actingAs($admin)
        ->get(route('admin.activity.index', ['per_page' => $requested]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.per_page', 50)
            ->has('activities.data', 50));
})->with([100000, 30, 0, -25, 'abc', '25abc', '']);

it('rejects a page size handed over as an array rather than crashing', function () {
    $admin = pageSizeAdmin();
    seedActivity(60);

    // `?per_page[]=25` makes the query value an array. It is not on the list either way,
    // but the point of the test is that the request completes at all: the equivalent shape
    // on `subject_id` is what once produced an unrendered 500 on this very controller.
    $this->actingAs($admin)
        ->get(route('admin.activity.index', ['per_page' => [25]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('filters.per_page', 50));
});

it('keeps the page size on the second page and moves on to the next slice', function () {
    $admin = pageSizeAdmin();
    seedActivity(120);

    // Newest first, so page one runs "entry 119" down to "entry 95" and page two starts
    // at "entry 94". Asserting the description — not just the row count — is what makes
    // this fail if the size were dropped on paging: 25 rows would still come back.
    $this->actingAs($admin)
        ->get(route('admin.activity.index', ['per_page' => 25, 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.per_page', 25)
            ->has('activities.data', 25)
            ->where('activities.data.0.description', 'entry 94'));
});

it('offers the page sizes to the page rather than letting the selector invent its own', function () {
    // The selector's options and the server's whitelist are one list. Hardcoding it in the
    // component is how the two drift until an offered option silently falls back to 50.
    $this->actingAs(pageSizeAdmin())
        ->get(route('admin.activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('pageSizes', [25, 50, 100]));
});
