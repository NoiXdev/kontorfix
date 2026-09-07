<?php

/*
 * The entry band above the portal's package list (spec §3.1): the registries it picks from
 * and — the only real logic on that page — the three states its collapse rule distinguishes.
 *
 * PortalPackageListTest owns the package payload of the same page. This file owns only the
 * band's own props, and in particular the rule that the band collapses once a token OF THIS
 * ORGANIZATION HAS BEEN USED. That answer comes from `registry_tokens.last_used_at` and never
 * from a dismissal flag in browser storage: a per-browser flag disagrees between the
 * customer's laptop and their CI machine, and there is nothing here a browser could tell us.
 */

use App\Models\Domain;
use App\Models\Group;
use App\Models\Organization;
use App\Models\RegistryToken;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * An organization with a portal registry and a member to open it with.
 *
 * @return array{0: Organization, 1: Group, 2: User}
 */
function bandFixture(string $slug = 'acme'): array
{
    $org = Organization::factory()->create(['slug' => $slug]);
    $group = Group::factory()->for($org)->create(['name' => 'Intern', 'portal_enabled' => true]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    return [$org, $group, $user];
}

it('asks for a first token when the organization has none', function () {
    [$org, $group, $user] = bandFixture();

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->component('portal/Packages')
            ->where('setupState', 'none')
            // Null and not an object: the collapsed line is the only reader of this, and a
            // populated value here would be data for a state that never renders it.
            ->where('lastUsedToken', null)
            ->where('registries.0.id', $group->id)
            ->where('registries.0.name', 'Intern')
            ->etc());
});

it('keeps the band open for a token that exists but was never used', function () {
    [$org, $group, $user] = bandFixture();
    // The middle state, and the one a two-state rule gets wrong: a token in hand is not a
    // finished setup. Nothing has authenticated with it, so the customer's tool is still
    // unconfigured and the steps are still what they need.
    RegistryToken::factory()->for($org)->create(['name' => 'ci-token', 'last_used_at' => null]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('setupState', 'unused')
            ->where('lastUsedToken', null)
            ->etc());
});

it('collapses once a token of the organization has been used', function () {
    [$org, $group, $user] = bandFixture();
    RegistryToken::factory()->for($org)->create(['name' => 'ci-token', 'last_used_at' => now()->subHours(2)]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('setupState', 'used')
            ->where('lastUsedToken.name', 'ci-token')
            // German, and pinned as a value rather than as "some string": `app.locale` is
            // `en` on this instance, so an unqualified diffForHumans() renders "2 hours ago"
            // inside a German sentence. The controller sets the locale explicitly and this
            // is what measures that it did.
            ->where('lastUsedToken.used_at', 'vor 2 Stunden')
            ->etc());
});

it('collapses for a token bound to a single registry', function () {
    [$org, $group, $user] = bandFixture();
    // `registry_tokens.group_id` is nullable — null means every registry of the organization
    // — and both kinds count. The rule is about the ORGANIZATION being connected, and a
    // customer whose one token is scoped to their one registry is as connected as it gets.
    RegistryToken::factory()->for($org)->for($group)->create(['name' => 'intern-token', 'last_used_at' => now()->subHours(2)]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('setupState', 'used')->etc());
});

it('reopens the band when the only used token was revoked', function () {
    [$org, $group, $user] = bandFixture();
    RegistryToken::factory()->for($org)->create([
        'name' => 'ci-token',
        'last_used_at' => now()->subHours(2),
        'revoked_at' => now()->subHour(),
    ]);

    // A revoked credential is dead for resolution, so the customer is NOT connected any more
    // — they need a new token, and the band is the page that says so. A rule that read
    // `last_used_at` alone would leave them on a one-line "Zugang eingerichtet" while every
    // build 401s.
    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('setupState', 'none')
            ->where('lastUsedToken', null)
            ->etc());
});

it('reopens the band when the only used token has expired', function () {
    [$org, $group, $user] = bandFixture();
    RegistryToken::factory()->for($org)->create([
        'name' => 'ci-token',
        'last_used_at' => now()->subMonth(),
        'expires_at' => now()->subDay(),
    ]);

    // The same reason as revocation, by the other route: findByPlainText() refuses an expired
    // token, so it buys the customer nothing. The two are asserted separately because they
    // are two clauses — dropping either one leaves the other green.
    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('setupState', 'none')->etc());
});

it('keeps a live token that has not expired yet', function () {
    [$org, $group, $user] = bandFixture();
    // The boundary on the other side of the expiry clause: `expires_at` in the FUTURE is the
    // ordinary shape of a token with a rotation policy, and a clause written as
    // `whereNull('expires_at')` alone would call every one of them dead.
    RegistryToken::factory()->for($org)->create(['last_used_at' => now()->subHours(2), 'expires_at' => now()->addYear()]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('setupState', 'used')->etc());
});

it('does not read another organization\'s tokens', function () {
    [$org, $group, $user] = bandFixture();
    $other = Organization::factory()->create(['slug' => 'other']);
    RegistryToken::factory()->for($other)->create(['last_used_at' => now()->subHours(2)]);

    // Tenancy, on the one field of this payload that crosses organizations at all. Without
    // the `organization_id` clause the band would collapse for every customer as soon as any
    // customer anywhere used a token.
    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('setupState', 'none')->etc());
});

it('names the most recently used token when several have been used', function () {
    [$org, $group, $user] = bandFixture();
    RegistryToken::factory()->for($org)->create(['name' => 'alt', 'last_used_at' => now()->subDays(3)]);
    RegistryToken::factory()->for($org)->create(['name' => 'laptop', 'last_used_at' => now()->subHours(2)]);

    // The line says WHICH credential is live, so the pick has to be the newest use rather
    // than whichever row the database returns first — the difference between "your CI is
    // connected" and a name the customer stopped using months ago.
    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('lastUsedToken.name', 'laptop')
            ->where('lastUsedToken.used_at', 'vor 2 Stunden')
            ->etc());
});

it('picks the same token twice when two were used in the same second', function () {
    [$org, $group, $user] = bandFixture();
    // FROZEN, so both rows really do carry one `created_at`. That is not a contrivance: the
    // column is `timestamp(0)` — what `$table->timestamps()` writes — so any two tokens minted
    // in one request are tied on it, and the ordering has nothing left to say unless a column
    // the schema can distinguish breaks the tie.
    $this->freezeTime();
    $usedAt = now()->subHours(2);

    // The LARGER id is inserted FIRST, so insertion order and id order disagree: without the
    // `id` tie-break the scan hands back `zzz` and the customer reads a different name than
    // they did on their last reload. Ids are assigned explicitly rather than left to
    // HasUuids, because a random pair would make this assertion a coin toss.
    $later = RegistryToken::factory()->for($org)->make(['name' => 'zzz', 'last_used_at' => $usedAt]);
    $later->id = '00000000-0000-4000-8000-000000000002';
    $later->save();

    $earlier = RegistryToken::factory()->for($org)->make(['name' => 'aaa', 'last_used_at' => $usedAt]);
    $earlier->id = '00000000-0000-4000-8000-000000000001';
    $earlier->save();

    // An arbitrary pick between two equals, but a STABLE one — which is the whole claim the
    // ordering makes.
    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('lastUsedToken.name', 'aaa')->etc());
});

it('names the ecosystems the organization may serve, and no others', function () {
    [$org, $group, $user] = bandFixture();
    // The instance permits three; this organization is narrowed to two of them. The band's
    // second step names what its own button then leads to — RegistryController::show() builds
    // the Einrichtung tab from this same call — so the two pages cannot name different tools.
    SystemSetting::current()->update(['enabled_registry_types' => ['composer', 'npm', 'docker']]);
    $org->update(['enabled_registry_types' => ['composer', 'docker', 'python']]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            // Intersected, not unioned: `python` is off instance-wide and must not reappear
            // because the organization asked for it.
            ->where('setupTypes', ['composer', 'docker'])
            ->etc());
});

it('sends an empty ecosystem list for an organization that may serve nothing', function () {
    [$org, $group, $user] = bandFixture();
    // A state the console accepts and stores. The band says so rather than naming four
    // ecosystems and sending the customer to a page that answers `noEcosystemMessage()`.
    $org->update(['enabled_registry_types' => []]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page->where('setupTypes', [])->etc());
});

it('prefers a used token over an unused one whatever order the rows are in', function () {
    [$org, $group, $user] = bandFixture();
    // The unused token is created LAST, so a plain `latest()` — or PostgreSQL's default
    // `NULLS FIRST` on a descending sort — would hand back the row with no `last_used_at`
    // and report a connected customer as `unused`.
    RegistryToken::factory()->for($org)->create(['name' => 'ci-token', 'last_used_at' => now()->subHours(2)]);
    RegistryToken::factory()->for($org)->create(['name' => 'fresh', 'last_used_at' => null]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            ->where('setupState', 'used')
            ->where('lastUsedToken.name', 'ci-token')
            ->etc());
});

it('carries every portal registry with its address, and no hidden one', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);
    // Named so that the alphabetical order the controller asks for is observable: `Images`
    // sorts before `Intern`, which the creation order below reverses.
    $intern = Group::factory()->for($org)->create(['name' => 'Intern', 'slug' => 'intern', 'portal_enabled' => true]);
    $images = Group::factory()->for($org)->create(['name' => 'Images', 'slug' => 'images', 'portal_enabled' => true]);
    Group::factory()->for($org)->create(['name' => 'Aaa-versteckt', 'portal_enabled' => false]);
    Domain::factory()->for($images)->create(['hostname' => 'images.acme.test']);
    $user = User::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user)->get('/c/acme')
        ->assertInertia(fn ($page) => $page
            // Two rows, not three: `groups.portal_enabled` is the same predicate
            // PortalPackages and the registries page ask, and the band's button must not
            // lead somewhere GroupPolicy::view() answers 403 to. The hidden registry sorts
            // FIRST by name, so its absence is measurable rather than incidental.
            ->has('registries', 2)
            ->where('registries.0.id', $images->id)
            ->where('registries.0.name', 'Images')
            // RegistryUrl::base(): the custom domain where there is one, and the canonical
            // path where there is not. Both branches asserted, because the address is the
            // whole point of the row and a single-branch fixture cannot tell the service's
            // answer from a formatted slug.
            ->where('registries.0.url', 'https://images.acme.test')
            ->where('registries.1.id', $intern->id)
            ->where('registries.1.url', config('app.url').'/r/acme/intern')
            ->etc());
});

it('asks for the token state once, however many registries the portal shows', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    foreach (['a', 'b', 'c'] as $slug) {
        Group::factory()->for($org)->create(['name' => $slug, 'slug' => $slug, 'portal_enabled' => true]);
    }

    RegistryToken::factory()->for($org)->create(['last_used_at' => now()->subHours(2)]);
    $user = User::factory()->create(['organization_id' => $org->id]);

    /** @var list<string> $queries */
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries) {
        if (str_contains($query->sql, 'registry_tokens')) {
            $queries[] = $query->sql;
        }
    });

    $this->actingAs($user)->get('/c/acme')->assertOk();

    // ONE statement against `registry_tokens`, with three registries on the page. Asking each
    // registry for its own tokens is the shape this pins shut: it reads identically on a
    // fixture with one registry, and grows with the customer.
    expect($queries)->toHaveCount(1);
});

it('does not re-fetch the organization it already holds', function () {
    $org = Organization::factory()->create(['slug' => 'acme']);

    foreach (['a', 'b', 'c'] as $slug) {
        Group::factory()->for($org)->create(['name' => $slug, 'slug' => $slug, 'portal_enabled' => true]);
    }

    $user = User::factory()->create(['organization_id' => $org->id]);

    /** @var list<string> $eagerLoads */
    $eagerLoads = [];
    DB::listen(function (QueryExecuted $query) use (&$eagerLoads) {
        // The shape an eager load of a belongsTo makes, and nothing else: the middleware's own
        // `where "slug" = ?` lookup and the membership joins are this page's legitimate reads
        // of the table.
        if (str_contains($query->sql, 'from "organizations"') && str_contains($query->sql, '"organizations"."id" in')) {
            $eagerLoads[] = $query->sql;
        }
    });

    $this->actingAs($user)->get('/c/acme')->assertOk();

    // RegistryUrl::base() reads `$group->organization` for the canonical path, and the
    // organization is the row PortalContext already handed this action. The controller sets
    // the relation instead of eager-loading it — the trick ResolveRegistryContext states for
    // the same call — so the statement is not issued at all.
    expect($eagerLoads)->toBe([]);
});
