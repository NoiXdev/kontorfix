<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Stamped ONLY by ResolvesOciRepository::ociCreateRepository(), which is what
            // makes it a provenance marker rather than a second created_at. The sweeper
            // needs to tell "a broken push left this empty row behind" from "an operator
            // registered this repository and has not pushed to it yet"; deleting the
            // second kind is a worse failure than leaving the first.
            $table->timestamp('auto_created_at')->nullable();
            $table->index('auto_created_at');
        });

        Schema::table('system_settings', function (Blueprint $table) {
            // The blob grace period. Instance-wide and deliberately NOT narrowable per
            // organization the way enabled_registry_types and oci_auto_create_repositories
            // are: those are features, and narrowing means having less of a feature. This
            // is a safety margin on a delete path, and "organization A's pushes get less
            // protection than organization B's" is not a preference anyone should be able
            // to express.
            $table->unsignedInteger('oci_blob_grace_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn('oci_blob_grace_hours');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['auto_created_at']);
            $table->dropColumn('auto_created_at');
        });
    }
};
