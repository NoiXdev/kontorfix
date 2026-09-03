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
        });
    }
};
