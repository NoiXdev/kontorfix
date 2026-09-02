<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the backfilled column into the real constraint.
 *
 * It refuses rather than repairs. A package attached to no registry has no derivable owner,
 * and two production incidents in v0.7.0 came from hardening that assumed existing data
 * already satisfied a new rule. The upgrade stops with the offending rows named, so the
 * operator decides — attach it to a registry, or delete it — instead of finding their
 * packages moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ownerless = DB::table('packages')->whereNull('organization_id')
            ->get(['id', 'type', 'name']);

        if ($ownerless->isNotEmpty()) {
            $list = $ownerless->map(fn ($p): string => "  - {$p->type} {$p->name} ({$p->id})")->implode("\n");

            throw new RuntimeException(
                "Cannot enforce package ownership: these packages belong to no registry, so no owner can be derived.\n"
                ."Attach each to a registry, or delete it, then run the migration again.\n\n".$list
            );
        }

        $orglessGroups = DB::table('groups')->whereNull('organization_id')->get(['id', 'slug']);

        if ($orglessGroups->isNotEmpty()) {
            $list = $orglessGroups->map(fn ($g): string => "  - {$g->slug} ({$g->id})")->implode("\n");

            throw new RuntimeException(
                "Cannot enforce package ownership: these registries belong to no organization.\n"
                ."Assign each to an organization, then run the migration again.\n\n".$list
            );
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->dropUnique(['type', 'name']);
            $table->unique(['organization_id', 'type', 'name']);
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // The old index was global: one (type, name) pair, one owner. Once organizations
        // can legitimately share a name — the point of this migration — restoring it isn't
        // always possible. Refuse the same way up() does rather than let a bare unique
        // violation surface mid-rollback, which is the worst moment to discover it.
        $duplicates = DB::table('packages')
            ->select('type', 'name')
            ->groupBy('type', 'name')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $list = $duplicates->map(fn ($d): string => "  - {$d->type} {$d->name}")->implode("\n");

            throw new RuntimeException(
                'Cannot roll back package ownership enforcement: these (type, name) pairs are now held '
                ."by more than one organization, so the old global-uniqueness index cannot be restored.\n"
                ."Rename or remove the duplicates, then run the rollback again.\n\n".$list
            );
        }

        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'type', 'name']);
            $table->unique(['type', 'name']);
            $table->dropForeign(['organization_id']);
            $table->uuid('organization_id')->nullable()->change();
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->change();
        });
    }
};
