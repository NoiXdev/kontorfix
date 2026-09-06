<?php

namespace App\Services\Slugs;

use App\Rules\UnclaimedSlug;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The authoritative half of the invariant `App\Rules\UnclaimedSlug` states informally: a
 * slug may never name both an organization and a registry, because /r/{orgSlug}/{groupSlug}
 * and the legacy /r/{slug} route share one namespace (see that class's docblock for why a
 * collision is worse than an error).
 *
 * `UnclaimedSlug` runs as a validation rule, strictly before the insert/update it gates —
 * it reads a table that does not yet contain the row the request is about to write. Two
 * concurrent requests — one creating organization `foo`, one creating registry `foo` — can
 * both validate against a table missing the other's not-yet-committed row, both pass, and
 * both write: check-then-act, not atomic. This class closes that window by making the
 * check and the write one atomic unit, using a PostgreSQL session primitive rather than a
 * table lock (this application is PostgreSQL-only; there is no SQLite/MySQL fallback to
 * keep working):
 *
 *   `pg_advisory_xact_lock(hashtext($slug))` — a lock keyed on the **slug itself**, not on
 *   `organizations` or `groups`. Keying it on a table would let an organization-create and a
 *   registry-create for the *same* slug run concurrently without ever contending, which is
 *   exactly the race this exists to close. Keying it on the slug means any two writers
 *   racing for the string `foo` — whichever tables they are headed for — serialize on the
 *   same lock, and the second one to acquire it re-reads the invariant after the first has
 *   committed (an xact-scoped advisory lock blocks a second acquirer until the holder's
 *   transaction ends, at which point its write, if any, is visible).
 *
 *   `hashtext()` collisions are harmless here: two unrelated slugs that happen to hash to
 *   the same 32-bit key contend for the same lock and briefly serialize against each other,
 *   which costs a little latency and nothing else. They do not share rows, tables, or
 *   correctness — the actual invariant is still enforced by the `SELECT … WHERE slug = ?`
 *   re-assertions below, not by the lock key being collision-free.
 *
 * Locking the slug also closes a second, pre-existing race that is not this class's named
 * purpose but shares its fix: two concurrent creates racing for the *same* table (two
 * `POST /admin/organizations` both naming slug `foo`, or two registries naming slug `foo` in
 * one organization) both pass their request's `Rule::unique`, then both serialize on this
 * same lock — its key is the slug, not the table, so same-table and cross-table racers
 * contend identically — and without a same-table re-assertion the loser would still reach
 * its `INSERT`, trip `organizations_slug_unique` / the `(organization_id, slug)` composite
 * constraint, and 500 rather than fail validation. Given the lock is already held, the extra
 * `SELECT` to close that off is cheap enough that leaving it undone would be leaving a gap
 * next to a fence built to close exactly this kind of gap.
 *
 * `UnclaimedSlug` **stays** alongside this class rather than being deleted as redundant: it
 * is what produces the German message in the console's common, non-racing path without a
 * transaction round trip, while this class is what actually holds the invariant. See that
 * class's docblock for the same note from its side — the two are meant to keep disagreeing
 * about nothing except when they run.
 *
 * A lock nobody else takes protects nothing: every write path that can set
 * `organizations.slug` or `groups.slug` — console and API, create and update, including the
 * setup wizard, which writes both in one request — must route through this class. The one
 * exception is `database/seeders/E2eSeeder.php`: it is single-threaded fixture data seeded
 * outside any request, with nothing else ever running concurrently against it, so there is
 * nothing for a lock to protect against there.
 */
class SlugClaimGuard
{
    /** Shown when a same-table race is lost — the organization-slug direction. */
    private const ORGANIZATION_SLUG_TAKEN = 'Dieser Slug ist bereits vergeben. Bitte einen anderen Slug wählen.';

    /** Shown when a same-table race is lost — the registry-slug direction. */
    private const REGISTRY_SLUG_TAKEN_IN_ORGANIZATION = 'Dieser Slug ist innerhalb dieser Organisation bereits vergeben. Bitte einen anderen Slug wählen.';

    /**
     * Claims $slug for an organization: locks it, re-asserts no registry already answers to
     * it AND that no other organization already holds it (organizations.slug is unique
     * instance-wide), then runs $write — all inside one transaction, so the lock
     * (transaction-scoped) covers the entire check-then-write.
     *
     * @param  string|null  $excludeOrganizationId  the row being updated, if any — so
     *                                              re-submitting a slug an organization
     *                                              already holds (itself, unchanged) is not
     *                                              mistaken for a same-table collision.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public function claimOrganizationSlug(string $slug, Closure $write, string $field = 'slug', ?string $excludeOrganizationId = null): mixed
    {
        return DB::transaction(function () use ($slug, $write, $field, $excludeOrganizationId) {
            $this->lock($slug);
            $this->assertNotRegisteredAsRegistry($slug, $field);
            $this->assertOrganizationSlugAvailable($slug, $field, $excludeOrganizationId);

            return $write();
        });
    }

    /**
     * Claims $slug for a registry: locks it, re-asserts no organization already answers to
     * it AND that no other registry in the same organization already holds it
     * (`groups`.`(organization_id, slug)` is a composite unique constraint, so the same slug
     * is fine in a different organization), then runs $write. Mirror of
     * claimOrganizationSlug() above.
     *
     * @param  string|null  $organizationId  the registry's organization, so the same-table
     *                                       re-assertion can be scoped the way the unique
     *                                       constraint is. Required (not defaulted) so a
     *                                       caller has to decide rather than silently skip
     *                                       the same-table check by forgetting the argument
     *                                       — the exact shape of gap this whole class exists
     *                                       to close. Pass `null` only when there is
     *                                       genuinely no organization to check yet:
     *                                       SetupController::store() claims a registry slug
     *                                       for an organization that does not exist yet at
     *                                       that point — brand new, so it holds no
     *                                       registries to collide with.
     * @param  string|null  $excludeGroupId  the row being updated, if any — see
     *                                       $excludeOrganizationId above.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public function claimRegistrySlug(string $slug, Closure $write, ?string $organizationId, string $field = 'slug', ?string $excludeGroupId = null): mixed
    {
        return DB::transaction(function () use ($slug, $write, $field, $organizationId, $excludeGroupId) {
            $this->lock($slug);
            $this->assertNotRegisteredAsOrganization($slug, $field);

            if ($organizationId !== null) {
                $this->assertRegistrySlugAvailableInOrganization($slug, $organizationId, $field, $excludeGroupId);
            }

            return $write();
        });
    }

    /**
     * The lock primitive on its own, for a caller that has to claim more than one slug (or
     * derive one) inside a single transaction and cannot express the whole thing as one
     * `$write` closure — see SetupController::store(), which writes an organization slug
     * and a registry slug together.
     *
     * Must run inside an open transaction, which the guard clause below enforces rather than
     * only documenting: outside one, `pg_advisory_xact_lock` runs in PostgreSQL's implicit
     * single-statement transaction and releases the instant that statement completes — not a
     * leak (that is `pg_advisory_lock`'s failure mode), but silent loss of protection, which
     * is both likelier to happen by accident and harder to notice than a lock that lingers.
     */
    public function lock(string $slug): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'SlugClaimGuard::lock() must run inside an open transaction: taken outside one, '
                .'pg_advisory_xact_lock releases the instant its own implicit transaction ends, '
                .'protecting nothing.'
            );
        }

        DB::select('select pg_advisory_xact_lock(hashtext(?))', [$slug]);
    }

    /** Re-assertion for a slug about to be written as an organization's. Call after lock(). */
    public function assertNotRegisteredAsRegistry(string $slug, string $field = 'slug'): void
    {
        if (DB::table('groups')->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([$field => UnclaimedSlug::TAKEN_BY_REGISTRY]);
        }
    }

    /** Re-assertion for a slug about to be written as a registry's. Call after lock(). */
    public function assertNotRegisteredAsOrganization(string $slug, string $field = 'slug'): void
    {
        if (DB::table('organizations')->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([$field => UnclaimedSlug::TAKEN_BY_ORGANIZATION]);
        }
    }

    /**
     * Same-table re-assertion: `organizations.slug` is unique instance-wide, so this is the
     * plain uniqueness check `Rule::unique('organizations', 'slug')` already runs at
     * validation time — re-run here, under the lock, so the second of two racing writers
     * fails validation instead of the database's unique index. Call after lock().
     */
    public function assertOrganizationSlugAvailable(string $slug, string $field = 'slug', ?string $excludeOrganizationId = null): void
    {
        $query = DB::table('organizations')->where('slug', $slug);

        if ($excludeOrganizationId !== null) {
            $query->where('id', '!=', $excludeOrganizationId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([$field => self::ORGANIZATION_SLUG_TAKEN]);
        }
    }

    /**
     * Same-table re-assertion: `groups.(organization_id, slug)` is a composite unique
     * constraint, so this mirrors what `Rule::unique('groups', 'slug')->where('organization_id', …)`
     * already runs at validation time — re-run here, under the lock. Call after lock().
     */
    public function assertRegistrySlugAvailableInOrganization(string $slug, string $organizationId, string $field = 'slug', ?string $excludeGroupId = null): void
    {
        $query = DB::table('groups')->where('organization_id', $organizationId)->where('slug', $slug);

        if ($excludeGroupId !== null) {
            $query->where('id', '!=', $excludeGroupId);
        }

        if ($query->exists()) {
            throw ValidationException::withMessages([$field => self::REGISTRY_SLUG_TAKEN_IN_ORGANIZATION]);
        }
    }
}
