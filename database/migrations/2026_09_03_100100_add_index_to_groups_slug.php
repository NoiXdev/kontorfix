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
 * always has the organization first — but the legacy redirect path (LegacySlugRedirector,
 * used by both LegacySlugRedirectController and ResolveRegistryContext's own fallback for a
 * canonical route a legacy URL happens to satisfy syntactically) looks up a group by slug
 * alone, with no organization to seek by. Without an index on `slug` by itself, every
 * request from a not-yet-migrated customer's composer.json/.npmrc/pip.conf — plausibly all
 * of a customer's traffic until they update their config — is a sequential scan over every
 * registry on the instance.
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
