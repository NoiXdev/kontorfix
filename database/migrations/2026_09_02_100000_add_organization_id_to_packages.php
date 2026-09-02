<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A package has had no owner of its own: it reached an organization only through the
 * registries it was attached to, which is why GuardsPackageAttachment had to re-derive one
 * at every call site and why a deleted registry left an orphan any tenant could claim.
 *
 * Nullable here on purpose. The backfill is one statement, but a package attached to no
 * registry has nothing to derive from, and guessing an owner for someone's packages is not
 * a migration's decision to make. The companion migration refuses to continue while any
 * remain and names them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->uuid('organization_id')->nullable()->after('id');
        });

        // The oldest registry wins where a package somehow sits in several. Deterministic
        // rather than arbitrary; the data shows no package in more than one organization.
        DB::statement(<<<'SQL'
            UPDATE packages p
               SET organization_id = sub.organization_id
              FROM (
                    SELECT DISTINCT ON (gp.package_id)
                           gp.package_id, g.organization_id
                      FROM group_package gp
                      JOIN groups g ON g.id = gp.group_id
                     WHERE g.organization_id IS NOT NULL
                     ORDER BY gp.package_id, g.created_at, g.id
                   ) sub
             WHERE p.id = sub.package_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
};
