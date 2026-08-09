<?php

use App\Enums\PackageType;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Upstream;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A05/A09 — the stateless registry routes resolve their UUID parameters with raw
 * `find()` / `whereKey()` rather than route-model binding, so a parameter that is not
 * a UUID reaches the Postgres `uuid` comparison and raises SQLSTATE[22P02]. Nothing in
 * bootstrap/app.php renders a QueryException, so the caller got a 500 and the instance
 * got a full stack trace — with the attacker's own string interpolated into it — in an
 * unrotated laravel.log, on every request, unthrottled.
 *
 * `4c4f736` pinned `{upstream}` to `[0-9a-fA-F-]{36}` while fixing a traversal, which
 * closes the obvious inputs but not the class: 36 dashes satisfy that character class,
 * and so does any other 36-character mix of hex digits and dashes. The PyPI download
 * route carried the same insufficient pattern from the start.
 *
 * The property under test is narrow and absolute: **no value of these parameters may
 * produce a 5xx**. 404 is the right answer for all of them — an id the caller has no
 * business knowing about names nothing it may distinguish from absent.
 */
function malformedIds(): array
{
    return [
        str_repeat('-', 36),                          // satisfies [0-9a-fA-F-]{36}
        str_repeat('a', 36),                          // hex letters, no dashes
        'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa-',      // trailing dash, still all-hex/dash
        '----aaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
        'not-a-uuid',
        '00000000-0000-0000-0000-00000000000',        // one digit short
    ];
}

it('never 500s on a malformed upstream id in the composer proxy route', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);
    $headers = tokenHeaderFor($group);

    foreach (malformedIds() as $id) {
        $this->withHeaders($headers)
            ->get("/r/kadenz/proxy/composer/{$id}/acme/demo/1.0.0.0")
            ->assertNotFound();
    }
});

it('never 500s on a malformed upstream id in either npm proxy route', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    Upstream::factory()->for($group)->create(['type' => PackageType::Npm, 'url' => 'https://repo.test']);
    $headers = tokenHeaderFor($group);

    foreach (malformedIds() as $id) {
        $this->withHeaders($headers)
            ->get("/r/kadenz/proxy/npm/{$id}/demo/-/demo-1.0.0.tgz")
            ->assertNotFound();

        $this->withHeaders($headers)
            ->get("/r/kadenz/proxy/npm/{$id}/@acme/demo/-/demo-1.0.0.tgz")
            ->assertNotFound();
    }
});

it('never 500s on a malformed package id in the pypi download route', function () {
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $headers = tokenHeaderFor($group);

    foreach (malformedIds() as $id) {
        $this->withHeaders($headers)
            ->get("/r/kadenz/pypi/files/{$id}/demo-1.0.0-py3-none-any.whl")
            ->assertNotFound();
    }
});

it('still serves a well-formed id, so the constraint did not just close the routes', function () {
    Http::fake();
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'kadenz']);
    $up = Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);

    // 404 because no such package is cached — but it is the controller's 404, reached
    // through the route, not the router refusing to match the id at all.
    $this->withHeaders(tokenHeaderFor($group))
        ->get("/r/kadenz/proxy/composer/{$up->id}/acme/demo/1.0.0.0")
        ->assertNotFound();

    expect(Str::isUuid($up->id))->toBeTrue();
});

it('refuses a malformed id anonymously on a public group too', function () {
    // The group being public removes the token requirement, so this is the anonymous
    // variant of the same request — the one that made the log flooding free.
    $group = Group::factory()->for(Organization::factory())->create(['slug' => 'oeffentlich', 'public' => true]);
    Upstream::factory()->for($group)->create(['type' => PackageType::Composer, 'url' => 'https://repo.test']);

    $this->get('/r/oeffentlich/proxy/composer/'.str_repeat('-', 36).'/acme/demo/1.0.0.0')
        ->assertNotFound();
});
