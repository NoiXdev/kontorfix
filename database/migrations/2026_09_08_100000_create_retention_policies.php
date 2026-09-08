<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Instance-wide, with no organization_id: policies are operator-defined and
            // selected per package, so scoping them per organization would create a
            // second, weaker place for the same decision to live.
            $table->string('name')->unique();
            // jsonb, not text: the rule list is read as structure by the evaluator and
            // rendered as structure by the editor. Never queried BY rule contents — the
            // evaluator loads a policy's whole set at once — so no GIN index.
            $table->jsonb('rules');
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            // nullOnDelete, NEVER cascade: a policy is a cleanup rule, and deleting one
            // must not delete the repositories it applied to. Null is also the meaningful
            // fallback rather than a missing value — the resolution chain reads it as
            // "ask the next tier".
            $table->foreignUuid('retention_policy_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('system_settings', function (Blueprint $table) {
            // The instance default, beside the other instance-wide switches. Ships null,
            // so a fresh installation deletes nothing until an operator says otherwise.
            $table->foreignUuid('retention_policy_id')->nullable()->constrained()->nullOnDelete();
        });

        // A tag's own "when was this last pushed", which no existing column answers.
        // ManifestStore::put() re-points a tag with updateOrCreate, so re-pushing a tag at
        // an unchanged manifest changes no attribute and moves no timestamp — and
        // OciTag::scopeInPullOrder()'s docblock records that as deliberate for the pull
        // ordering. Two of the four retention rule types need "pushed", not "re-pointed":
        // without this column a CI job that re-pushes `latest` nightly at the same digest
        // would age out under "keep newer than N days" while the client believes it has
        // pushed it every night.
        Schema::table('oci_tags', function (Blueprint $table) {
            $table->timestamp('pushed_at')->nullable();
        });

        // Backfilled from updated_at, then made NOT NULL with a database default, so no
        // caller and no rule has to carry a null branch. A nullable timestamp read by a
        // delete path is a fail-open that every reader would have to remember to close.
        DB::statement('update oci_tags set pushed_at = updated_at where pushed_at is null');
        DB::statement('alter table oci_tags alter column pushed_at set default now()');
        DB::statement('alter table oci_tags alter column pushed_at set not null');

        Schema::table('oci_tags', function (Blueprint $table) {
            // keep_last orders by this within one repository.
            $table->index(['package_id', 'pushed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('oci_tags', function (Blueprint $table) {
            $table->dropIndex(['package_id', 'pushed_at']);
            $table->dropColumn('pushed_at');
        });

        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retention_policy_id');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retention_policy_id');
        });

        Schema::dropIfExists('retention_policies');
    }
};
