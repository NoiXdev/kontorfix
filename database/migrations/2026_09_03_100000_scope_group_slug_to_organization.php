<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A registry slug becomes unique per organization rather than instance-wide, which is what
 * lets two customers each call a registry `packages`. The organization then has to appear
 * in the URL — /r/{orgSlug}/{groupSlug} — because a slug alone no longer identifies one
 * registry.
 *
 * The refusal below guards the legacy redirect. Old /r/{slug} URLs are answered by a
 * one-segment route registered after the canonical one, and Laravel matches the first
 * route that fits. So if an organization slug equals some registry's slug, /r/acme/foo
 * matches the canonical form first and resolves to registry `foo` of organization `acme` —
 * silently serving a different registry than the legacy URL meant. A wrong answer is worse
 * than an error, so the upgrade stops and names the pairs instead.
 *
 * `groups.legacy_slug` freezes the pre-upgrade one-segment address, and it is added HERE
 * rather than in a migration of its own for one reason: this is the last moment at which
 * the value being frozen is provably unique across the whole instance. Until the
 * dropUnique(['slug']) at the bottom of up(), `groups_slug_unique` is still live, so every
 * registry's slug is globally distinct *by database constraint* — not by inference. A
 * later migration could only assume that nothing wrote a duplicate in between, which is
 * true within one `migrate` run and false the moment a run is interrupted and the instance
 * serves traffic before the rest of it goes through. Freezing the address next to the
 * constraint that guarantees it keeps the two from drifting apart.
 *
 * The collision refusal above is the other half of the guarantee: it also proves no frozen
 * legacy address equals an organization slug, so no legacy address can ever be shadowed by
 * the canonical two-segment route.
 *
 * That is what makes the legacy redirect safe once slugs are only per-organization unique.
 * A registry created *after* this migration gets `legacy_slug = NULL` and can therefore
 * never capture the old address of an incumbent that happens to share its slug; renaming a
 * registry clears the column (App\Models\Group::booted()), preserving the decision that a
 * renamed slug gets no alias. See App\Services\Registry\LegacySlugRedirector.
 *
 * This migration has never shipped in a release — it is introduced on the same branch as
 * the column — so amending it is legitimate; a released one would have had to stay frozen.
 *
 * That legitimacy has a sharp edge for anyone who already ran this branch locally before
 * this file was amended: Laravel's `migrations` table keys a run on filename
 * (`2026_09_03_100000_scope_group_slug_to_organization`), not on content, so an already-applied
 * copy is never re-run and the amendment never reaches that database. Concretely: an earlier
 * revision of this migration did not add `groups.legacy_slug` at all, so a developer who ran
 * that revision has a `groups` table with no such column — and hits `column "legacy_slug" does
 * not exist` at the first legacy-slug lookup or slug rename, not at migrate time. `migrate:rollback`
 * does not repair this either: `down()` on the *currently checked-out* file tries to drop a
 * column and unique index that were never added, which fails; Postgres runs each migration in
 * its own transaction, so that failure aborts atomically rather than leaving a half-applied
 * schema, but it still leaves the instance on the stale revision. The fix is a fresh migrate
 * (`php artisan migrate:fresh`, never against a shared/production database) rather than a
 * rollback-and-reapply.
 */
return new class extends Migration
{
    public function up(): void
    {
        $collisions = DB::table('organizations as o')
            ->join('groups as g', 'g.slug', '=', 'o.slug')
            ->orderBy('o.slug')
            ->get(['o.slug as org_slug', 'o.id as org_id', 'g.id as group_id']);

        if ($collisions->isNotEmpty()) {
            $list = $collisions
                ->map(fn ($c): string => "  - slug \"{$c->org_slug}\": organization {$c->org_id}, registry {$c->group_id}")
                ->implode("\n");

            throw new RuntimeException(
                'Cannot scope registry slugs to their organization: these slugs name both an '
                ."organization and a registry.\nAn old /r/<slug> URL would then resolve to the "
                ."wrong registry. Rename one side of each pair, then run the migration again.\n\n".$list
            );
        }

        // Order matters: the column is filled while `groups_slug_unique` still stands, so
        // the unique index on `legacy_slug` cannot fail and the frozen addresses cannot
        // collide. Doing this after the swap below would make the same statement an
        // assumption instead of a fact (see the class docblock).
        Schema::table('groups', function (Blueprint $table) {
            // Nullable and unique: NULL is "has no legacy address" and Postgres does not
            // treat NULLs as equal, so any number of post-upgrade registries coexist.
            $table->string('legacy_slug')->nullable()->unique();
        });

        DB::table('groups')->update(['legacy_slug' => DB::raw('slug')]);

        Schema::table('groups', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->unique(['organization_id', 'slug']);
        });
    }

    public function down(): void
    {
        $duplicates = DB::table('groups')
            ->select('slug')->groupBy('slug')->havingRaw('count(*) > 1')->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->map(fn ($d): string => "  - {$d->slug}")->implode("\n");

            throw new RuntimeException(
                'Cannot restore instance-wide registry slug uniqueness: these slugs are now held '
                ."by more than one organization.\nRename the duplicates, then run the rollback "
                ."again.\n\n".$list
            );
        }

        Schema::table('groups', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'slug']);
            $table->unique(['slug']);
            // The frozen addresses go with the scoping that made them necessary: with
            // instance-wide uniqueness restored, a bare slug identifies one registry again
            // and there is nothing left for a separate legacy address to disambiguate.
            // Dropping the column takes its unique index with it.
            $table->dropColumn('legacy_slug');
        });
    }
};
