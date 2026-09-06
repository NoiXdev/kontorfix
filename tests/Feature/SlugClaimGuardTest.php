<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Services\Slugs\SlugClaimGuard;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/**
 * App\Rules\UnclaimedSlug is check-then-act: it reads `organizations`/`groups` before the
 * request that passed validation gets around to writing its own row, so two concurrent
 * requests — one minting organization `foo`, one minting registry `foo` — can both read a
 * table missing the other's not-yet-committed row, both pass, and both insert.
 * SlugClaimGuard closes that window with a `pg_advisory_xact_lock` keyed on the slug plus a
 * re-assertion, both inside the same transaction as the write.
 *
 * A true concurrency test needs two overlapping connections and is not practical in Pest,
 * so this proves the two things that are practical instead:
 *
 *  (a) the re-assertion is genuinely authoritative, not merely the same check the rule
 *      already ran — called directly, bypassing the HTTP layer (and the rule) entirely;
 *  (b) every write path that can set `organizations.slug` or `groups.slug` actually
 *      protects its write, not merely "calls the guard somewhere". A lock taken and
 *      released in its own transaction ahead of the write would still make a naive
 *      "a pg_advisory_xact_lock statement appeared in the query log" assertion pass, while
 *      protecting nothing — a second racing writer would sail straight through the
 *      now-released lock and hit the write with no re-assertion standing guard. So
 *      assertLockGuardsWrite() below pins two properties a passing test must actually have:
 *      the lock statement is bound to *this request's own slug* (not merely some
 *      `pg_advisory_xact_lock` call, which could be a leftover from setup or an unrelated
 *      candidate), and no transaction commit occurs between that lock statement and the
 *      insert/update it is meant to cover — i.e. the two run inside the same database
 *      transaction. Verified by literally reverting `claimOrganizationSlug()` to take the
 *      lock in its own transaction ahead of `$write()` and confirming the tests using this
 *      helper go red.
 *
 * lock()'s own "must run inside an open transaction" precondition is tested separately, in
 * tests/Unit/Services/Slugs/SlugClaimGuardLockTest.php: every test in this file inherits
 * RefreshDatabase, which wraps the whole test in its own outer transaction, so
 * DB::transactionLevel() can never be observed at 0 here.
 */
function captureTransactionalTimeline(Closure $action): array
{
    $timeline = [];

    DB::listen(function ($query) use (&$timeline): void {
        $timeline[] = ['type' => 'query', 'sql' => $query->sql, 'bindings' => $query->bindings];
    });

    // TransactionCommitted fires for every DB::transaction() call, including a nested one
    // (Laravel represents a nested transaction as a savepoint, but still fires the event at
    // every level) — which is exactly the granularity needed here: a `commit` entry between
    // the lock and the write means they were NOT the same database transaction.
    Event::listen(TransactionCommitted::class, function () use (&$timeline): void {
        $timeline[] = ['type' => 'commit'];
    });

    $action();

    return $timeline;
}

/**
 * Asserts that locking $slug actually protects the write to $table: the lock statement's
 * own binding is $slug (not a different candidate, and not absent), a write to $table
 * follows it, and no transaction-commit event separates the two.
 */
function assertLockGuardsWrite(array $timeline, string $slug, string $table): void
{
    $lockIndex = null;

    foreach ($timeline as $i => $entry) {
        if ($entry['type'] === 'query'
            && str_contains($entry['sql'], 'pg_advisory_xact_lock')
            && $entry['bindings'] === [$slug]) {
            $lockIndex = $i;
            break;
        }
    }

    expect($lockIndex)->not->toBeNull();

    $writeIndex = null;

    foreach ($timeline as $i => $entry) {
        if ($i <= $lockIndex || $entry['type'] !== 'query') {
            continue;
        }

        if (preg_match('/^\s*(insert into|update)\s+"'.preg_quote($table, '/').'"/i', $entry['sql']) === 1) {
            $writeIndex = $i;
            break;
        }
    }

    expect($writeIndex)->not->toBeNull();

    $commitsBetween = collect($timeline)
        ->slice($lockIndex + 1, $writeIndex - $lockIndex - 1)
        ->filter(fn (array $entry): bool => $entry['type'] === 'commit')
        ->count();

    expect($commitsBetween)->toBe(0);
}

// --- (a) the re-assertion inside the guard is authoritative, independent of the rule ---

it('refuses to claim an organization slug a registry already holds, bypassing validation entirely', function () {
    Group::factory()->create(['slug' => 'kadenz']);

    expect(fn () => app(SlugClaimGuard::class)->claimOrganizationSlug(
        'kadenz',
        fn () => Organization::factory()->create(['slug' => 'kadenz']),
    ))->toThrow(ValidationException::class);

    // The write closure must never have run — the whole point of checking before writing.
    expect(Organization::where('slug', 'kadenz')->exists())->toBeFalse();
});

it('refuses to claim a registry slug an organization already holds, bypassing validation entirely', function () {
    Organization::factory()->create(['slug' => 'kadenz']);

    expect(fn () => app(SlugClaimGuard::class)->claimRegistrySlug(
        'kadenz',
        fn () => Group::factory()->create(['slug' => 'kadenz']),
    ))->toThrow(ValidationException::class);

    expect(Group::where('slug', 'kadenz')->exists())->toBeFalse();
});

it('refuses to claim an organization slug another organization already holds (same-table race)', function () {
    Organization::factory()->create(['slug' => 'kadenz']);

    expect(fn () => app(SlugClaimGuard::class)->claimOrganizationSlug(
        'kadenz',
        fn () => Organization::factory()->create(['slug' => 'kadenz']),
    ))->toThrow(ValidationException::class);

    expect(Organization::where('slug', 'kadenz')->count())->toBe(1);
});

it('lets an organization keep its own unchanged slug when excluded from the same-table check', function () {
    $org = Organization::factory()->create(['slug' => 'kadenz']);

    app(SlugClaimGuard::class)->claimOrganizationSlug(
        'kadenz',
        fn () => $org->update(['name' => 'Renamed']),
        excludeOrganizationId: $org->id,
    );

    expect($org->fresh()->name)->toBe('Renamed');
});

it('refuses to claim a registry slug another registry in the same organization already holds (same-table race)', function () {
    $org = Organization::factory()->create();
    Group::factory()->for($org)->create(['slug' => 'kadenz']);

    expect(fn () => app(SlugClaimGuard::class)->claimRegistrySlug(
        'kadenz',
        fn () => Group::factory()->for($org)->create(['slug' => 'kadenz']),
        organizationId: $org->id,
    ))->toThrow(ValidationException::class);

    expect(Group::where('organization_id', $org->id)->where('slug', 'kadenz')->count())->toBe(1);
});

it('lets two different organizations share a registry slug (same-table check is scoped)', function () {
    Group::factory()->create(['slug' => 'kadenz']);
    $otherOrg = Organization::factory()->create();

    $group = app(SlugClaimGuard::class)->claimRegistrySlug(
        'kadenz',
        fn () => Group::factory()->for($otherOrg)->create(['slug' => 'kadenz']),
        organizationId: $otherOrg->id,
    );

    expect($group)->toBeInstanceOf(Group::class)
        ->and(Group::where('slug', 'kadenz')->count())->toBe(2);
});

it('runs the write and returns its result when the slug is genuinely free', function () {
    $org = app(SlugClaimGuard::class)->claimOrganizationSlug(
        'freeslug',
        fn () => Organization::factory()->create(['slug' => 'freeslug']),
    );

    expect($org)->toBeInstanceOf(Organization::class)
        ->and(Organization::where('slug', 'freeslug')->exists())->toBeTrue();
});

// --- (b) every write path actually protects its write, not merely "calls the guard" -----

// Grouped under its own describe() so this file's setup-wizard test at the bottom — which
// needs the instance to hold no users at all — is not affected by this block's beforeEach.
describe('existing-instance write paths', function () {
    beforeEach(function () {
        $this->operator = Organization::factory()->create(['is_operator' => true]);
        $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
    });

    it('protects the write creating an organization from the console', function () {
        $timeline = captureTransactionalTimeline(fn () => $this->actingAs($this->admin)
            ->post('/admin/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz-console'])
            ->assertSessionHasNoErrors());

        assertLockGuardsWrite($timeline, 'kadenz-console', 'organizations');
        expect(Organization::where('slug', 'kadenz-console')->exists())->toBeTrue();
    });

    it('protects the write creating an organization over the api', function () {
        [, $plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);

        $timeline = captureTransactionalTimeline(fn () => $this->withToken($plain)
            ->postJson('/api/v1/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz-api'])
            ->assertCreated());

        assertLockGuardsWrite($timeline, 'kadenz-api', 'organizations');
        expect(Organization::where('slug', 'kadenz-api')->exists())->toBeTrue();
    });

    it('protects the write updating an organization slug from the console', function () {
        $org = Organization::factory()->create(['slug' => 'old-slug']);

        $timeline = captureTransactionalTimeline(fn () => $this->actingAs($this->admin)
            ->put("/admin/organizations/{$org->id}", [
                'name' => $org->name,
                'slug' => 'new-slug',
                'notification_cadence' => 'daily',
            ])->assertSessionHasNoErrors());

        assertLockGuardsWrite($timeline, 'new-slug', 'organizations');
        expect($org->fresh()->slug)->toBe('new-slug');
    });

    it('protects the write creating a registry from the console', function () {
        $timeline = captureTransactionalTimeline(fn () => $this->actingAs($this->admin)
            ->post('/admin/groups', ['name' => 'Pakete', 'slug' => 'pakete-console'])
            ->assertSessionHasNoErrors());

        assertLockGuardsWrite($timeline, 'pakete-console', 'groups');
        expect(Group::where('slug', 'pakete-console')->exists())->toBeTrue();
    });

    it('protects the write creating a registry over the api', function () {
        [, $plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);

        $timeline = captureTransactionalTimeline(fn () => $this->withToken($plain)
            ->postJson('/api/v1/groups', [
                'name' => 'Pakete', 'slug' => 'pakete-api', 'organization_id' => $this->operator->id,
            ])->assertCreated());

        assertLockGuardsWrite($timeline, 'pakete-api', 'groups');
        expect(Group::where('slug', 'pakete-api')->exists())->toBeTrue();
    });

    it('protects the write updating a registry slug from the console', function () {
        $group = Group::factory()->for($this->operator)->create(['slug' => 'old-registry']);

        $timeline = captureTransactionalTimeline(fn () => $this->actingAs($this->admin)
            ->put("/admin/groups/{$group->id}", [
                'name' => $group->name, 'slug' => 'new-registry', 'public' => false, 'portal_enabled' => false,
            ])->assertSessionHasNoErrors());

        assertLockGuardsWrite($timeline, 'new-registry', 'groups');
        expect($group->fresh()->slug)->toBe('new-registry');
    });

    it('protects the write updating a registry slug over the api', function () {
        [, $plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
        $group = Group::factory()->for($this->operator)->create(['slug' => 'old-api-registry']);

        $timeline = captureTransactionalTimeline(fn () => $this->withToken($plain)
            ->putJson("/api/v1/groups/{$group->id}", [
                'name' => $group->name, 'public' => false, 'slug' => 'new-api-registry',
            ])->assertOk());

        assertLockGuardsWrite($timeline, 'new-api-registry', 'groups');
        expect($group->fresh()->slug)->toBe('new-api-registry');
    });

    it('does not take any lock updating a registry that leaves its slug untouched', function () {
        // UpdateGroupRequest's slug rule is `sometimes` — a PUT naming only the fields it
        // wants changed must not be treated as a slug write at all, and must not pay for a
        // lock that protects nothing here.
        $group = Group::factory()->for($this->operator)->create(['slug' => 'unchanged']);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->admin)
            ->put("/admin/groups/{$group->id}", ['name' => 'Renamed', 'public' => false, 'portal_enabled' => false])
            ->assertSessionHasNoErrors();

        $tookLock = collect(DB::getQueryLog())
            ->contains(fn (array $entry): bool => str_contains($entry['query'], 'pg_advisory_xact_lock'));
        DB::disableQueryLog();

        expect($tookLock)->toBeFalse()
            ->and($group->fresh()->slug)->toBe('unchanged');
    });
});

it('protects both writes when the setup wizard mints an organization and a registry together', function () {
    // Deliberately outside the describe() block above: the setup gate only opens while the
    // instance holds no users at all, and that block's beforeEach creates one.
    $payload = [
        'admin_name' => 'Ada Admin',
        'admin_email' => 'ada@example.com',
        'admin_password' => 'correct-horse-battery-staple',
        'admin_password_confirmation' => 'correct-horse-battery-staple',
        'organization_name' => 'Acme GmbH',
        'registry_name' => 'Interne Pakete',
        'registry_slug' => 'interne-pakete',
        'registry_public' => false,
        'mailer' => 'log',
        'storage_driver' => 'local',
    ];

    $timeline = captureTransactionalTimeline(fn () => $this->post('/setup', $payload)->assertRedirect(route('dashboard')));

    $organization = Organization::sole();
    $group = Group::sole();

    // The registry slug is user-typed and known up front; the organization slug is derived,
    // so it is read back off the row the wizard actually created.
    assertLockGuardsWrite($timeline, $payload['registry_slug'], 'groups');
    assertLockGuardsWrite($timeline, $organization->slug, 'organizations');

    expect($organization->slug)->not->toBe('interne-pakete')
        ->and($group->slug)->toBe('interne-pakete');
});
