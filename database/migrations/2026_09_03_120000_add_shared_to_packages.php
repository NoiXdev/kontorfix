<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // A shared package is owned AND marked, never ownerless: organization_id stays
            // NOT NULL and belongs to the operator organization. Reusing a null owner for
            // "shared" would make it indistinguishable from the orphan v0.8.0's migration
            // refuses to run over, and Postgres does not collide NULLs in a unique index, so
            // the one category that must be unambiguous instance-wide would have no
            // uniqueness at all.
            $table->boolean('shared')->default(false)->after('organization_id');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('shared');
        });
    }
};
