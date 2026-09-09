<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Anonymous inline retention rules, valid only for this package — the same
            // grammar retention_policies.rules carries, deliberately WITHOUT a name or any
            // reuse (an operator decision, taken over the named-and-reusable alternative).
            // Null means "no inline tier"; the resolver falls through to the named policy,
            // then the instance default. Non-null inline rules win unconditionally, which
            // makes the instance default a default rather than a mandate — also a
            // deliberate reversal of the earlier super-only assignment decision.
            $table->jsonb('retention_rules')->nullable();
        });

        Schema::table('retention_policies', function (Blueprint $table) {
            // Visible to and selectable by every organization, editable only by the
            // operator. Customers author rules inline on their packages; named policies
            // stay operator things — global is a publication flag, not shared authorship.
            $table->boolean('is_global')->default(false);
        });

        Schema::table('git_credentials', function (Blueprint $table) {
            // Usable by every organization (read-only, secret never shown). The stated and
            // accepted risk: any customer admin can point a package at any private
            // repository this credential can read. permits() keeps binding it to one host.
            $table->boolean('is_global')->default(false);
        });

        // Targeted shares, beside the global flag: the operator grants one credential to
        // selected organizations. Usable = own OR global OR shared — the sync-time check
        // reads exactly this, so removing a row here ends the grant for the NEXT sync,
        // not only for the next assignment.
        Schema::create('git_credential_organization', function (Blueprint $table) {
            $table->foreignUuid('git_credential_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The pair IS the row — no surrogate id (attach() would never fill one), and
            // the primary key doubles as the "shared at most once" constraint.
            $table->primary(['git_credential_id', 'organization_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_credential_organization');

        Schema::table('git_credentials', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });

        Schema::table('retention_policies', function (Blueprint $table) {
            $table->dropColumn('is_global');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('retention_rules');
        });
    }
};
