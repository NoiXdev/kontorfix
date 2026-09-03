<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026_09_03_100000_scope_group_slug_to_organization dropped the unique index on
 * `groups.slug` alone (and with it, the index — a unique constraint in Postgres is backed
 * by an index, so dropping the constraint drops the index too), replacing it with a unique
 * index on (organization_id, slug). That composite index cannot be seeked by slug alone
 * (see the comment in ResolveRegistryContext), which is fine for the canonical lookup that
 * always has the organization first, but leaves nothing to seek for the lookups that carry
 * a bare slug and no organization. Without an index on `slug` by itself each of those is a
 * sequential scan over every registry on the instance.
 *
 * Corrected: this index was introduced for the legacy redirect path, which at the time
 * matched `groups.slug` directly. It no longer does — LegacySlugRedirector seeks the frozen
 * `groups.legacy_slug`, which carries a unique index of its own (added with the column in
 * 2026_09_03_100000). What still needs this index is the namespace check that keeps a slug
 * from naming both an organization and a registry: App\Rules\UnclaimedSlug::byRegistry() on
 * every organization create and slug edit, and SetupController's organization-slug
 * derivation, which loops until the candidate collides with neither table. Those are
 * console-rate rather than client-rate, so the index is no longer hot-path — but it is
 * still the difference between a seek and a full scan on a write path an operator waits on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex(['slug']);
        });
    }
};
