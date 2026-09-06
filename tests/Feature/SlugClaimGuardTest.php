<?php

use App\Enums\ApiKeyPermission;
use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\Group;
use App\Models\Organization;
use App\Models\User;
use App\Services\Slugs\SlugClaimGuard;
use Illuminate\Support\Facades\DB;
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
 *  (b) every write path that can set `organizations.slug` or `groups.slug` actually calls
 *      through the guard. That is checked by asserting the exact
 *      `pg_advisory_xact_lock` statement appears in the query log for the request — an
 *      assertion that fails the moment a path reverts to a bare `Model::create()`/
 *      `update()`, unlike asserting the row was written (which would keep passing).
 */
function assertAdvisoryLockWasTaken(Closure $action): void
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $action();

    $tookLock = collect(DB::getQueryLog())
        ->contains(fn (array $entry): bool => str_contains($entry['query'], 'pg_advisory_xact_lock'));

    DB::disableQueryLog();

    expect($tookLock)->toBeTrue();
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

it('runs the write and returns its result when the slug is genuinely free', function () {
    $org = app(SlugClaimGuard::class)->claimOrganizationSlug(
        'freeslug',
        fn () => Organization::factory()->create(['slug' => 'freeslug']),
    );

    expect($org)->toBeInstanceOf(Organization::class)
        ->and(Organization::where('slug', 'freeslug')->exists())->toBeTrue();
});

// --- (b) every write path actually takes the lock, not just the ones that happen to work -

// Grouped under its own describe() so this file's setup-wizard test at the bottom — which
// needs the instance to hold no users at all — is not affected by this block's beforeEach.
describe('existing-instance write paths', function () {
    beforeEach(function () {
        $this->operator = Organization::factory()->create(['is_operator' => true]);
        $this->admin = User::factory()->for($this->operator)->create(['role' => UserRole::Admin]);
    });

    it('takes the advisory lock creating an organization from the console', function () {
        assertAdvisoryLockWasTaken(fn () => $this->actingAs($this->admin)
            ->post('/admin/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz-console'])
            ->assertSessionHasNoErrors());

        expect(Organization::where('slug', 'kadenz-console')->exists())->toBeTrue();
    });

    it('takes the advisory lock creating an organization over the api', function () {
        [, $plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);

        assertAdvisoryLockWasTaken(fn () => $this->withToken($plain)
            ->postJson('/api/v1/organizations', ['name' => 'Kadenz GmbH', 'slug' => 'kadenz-api'])
            ->assertCreated());

        expect(Organization::where('slug', 'kadenz-api')->exists())->toBeTrue();
    });

    it('takes the advisory lock updating an organization slug from the console', function () {
        $org = Organization::factory()->create(['slug' => 'old-slug']);

        assertAdvisoryLockWasTaken(fn () => $this->actingAs($this->admin)
            ->put("/admin/organizations/{$org->id}", [
                'name' => $org->name,
                'slug' => 'new-slug',
                'notification_cadence' => 'daily',
            ])->assertSessionHasNoErrors());

        expect($org->fresh()->slug)->toBe('new-slug');
    });

    it('takes the advisory lock creating a registry from the console', function () {
        assertAdvisoryLockWasTaken(fn () => $this->actingAs($this->admin)
            ->post('/admin/groups', ['name' => 'Pakete', 'slug' => 'pakete-console'])
            ->assertSessionHasNoErrors());

        expect(Group::where('slug', 'pakete-console')->exists())->toBeTrue();
    });

    it('takes the advisory lock creating a registry over the api', function () {
        [, $plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);

        assertAdvisoryLockWasTaken(fn () => $this->withToken($plain)
            ->postJson('/api/v1/groups', [
                'name' => 'Pakete', 'slug' => 'pakete-api', 'organization_id' => $this->operator->id,
            ])->assertCreated());

        expect(Group::where('slug', 'pakete-api')->exists())->toBeTrue();
    });

    it('takes the advisory lock updating a registry slug from the console', function () {
        $group = Group::factory()->for($this->operator)->create(['slug' => 'old-registry']);

        assertAdvisoryLockWasTaken(fn () => $this->actingAs($this->admin)
            ->put("/admin/groups/{$group->id}", [
                'name' => $group->name, 'slug' => 'new-registry', 'public' => false, 'portal_enabled' => false,
            ])->assertSessionHasNoErrors());

        expect($group->fresh()->slug)->toBe('new-registry');
    });

    it('takes the advisory lock updating a registry slug over the api', function () {
        [, $plain] = ApiKey::issue($this->admin, 'w', ApiKeyPermission::Write);
        $group = Group::factory()->for($this->operator)->create(['slug' => 'old-api-registry']);

        assertAdvisoryLockWasTaken(fn () => $this->withToken($plain)
            ->putJson("/api/v1/groups/{$group->id}", [
                'name' => $group->name, 'public' => false, 'slug' => 'new-api-registry',
            ])->assertOk());

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

it('takes the advisory lock (for both slugs) when the setup wizard mints an organization and a registry together', function () {
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

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->post('/setup', $payload)->assertRedirect(route('dashboard'));

    $lockCalls = collect(DB::getQueryLog())
        ->filter(fn (array $entry): bool => str_contains($entry['query'], 'pg_advisory_xact_lock'))
        ->count();
    DB::disableQueryLog();

    // One lock for the user-typed registry slug, at least one more for the derived
    // organization slug (the derivation loop locks every candidate it tries, so this can be
    // more than two — it must never be fewer).
    expect($lockCalls)->toBeGreaterThanOrEqual(2)
        ->and(Organization::sole()->slug)->not->toBe('interne-pakete')
        ->and(Group::sole()->slug)->toBe('interne-pakete');
});
