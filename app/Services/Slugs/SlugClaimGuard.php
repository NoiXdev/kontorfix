<?php

namespace App\Services\Slugs;

use App\Rules\UnclaimedSlug;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
 *   re-assertion below, not by the lock key being collision-free.
 *
 * `UnclaimedSlug` **stays** alongside this class rather than being deleted as redundant: it
 * is what produces the German message in the console's common, non-racing path without a
 * transaction round trip, while this class is what actually holds the invariant. See that
 * class's docblock for the same note from its side — the two are meant to keep disagreeing
 * about nothing except when they run.
 *
 * A lock nobody else takes protects nothing: every write path that can set
 * `organizations.slug` or `groups.slug` — console and API, create and update, including the
 * setup wizard, which writes both in one request — must route through this class.
 */
class SlugClaimGuard
{
    /**
     * Claims $slug for an organization: locks it, re-asserts no registry already answers to
     * it, then runs $write — all inside one transaction, so the lock (transaction-scoped)
     * covers the entire check-then-write.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public function claimOrganizationSlug(string $slug, Closure $write, string $field = 'slug'): mixed
    {
        return DB::transaction(function () use ($slug, $write, $field) {
            $this->lock($slug);
            $this->assertNotRegisteredAsRegistry($slug, $field);

            return $write();
        });
    }

    /**
     * Claims $slug for a registry: locks it, re-asserts no organization already answers to
     * it, then runs $write. Mirror of claimOrganizationSlug() above.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     */
    public function claimRegistrySlug(string $slug, Closure $write, string $field = 'slug'): mixed
    {
        return DB::transaction(function () use ($slug, $write, $field) {
            $this->lock($slug);
            $this->assertNotRegisteredAsOrganization($slug, $field);

            return $write();
        });
    }

    /**
     * The lock primitive on its own, for a caller that has to claim more than one slug (or
     * derive one) inside a single transaction and cannot express the whole thing as one
     * `$write` closure — see SetupController::store(), which writes an organization slug
     * and a registry slug together. Must run inside an open transaction: PostgreSQL releases
     * an xact-scoped advisory lock only at COMMIT/ROLLBACK, so calling this outside one would
     * leak the lock for the rest of the connection's lifetime instead of the request's.
     */
    public function lock(string $slug): void
    {
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
}
