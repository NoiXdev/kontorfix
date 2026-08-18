<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Makes `source_mode` truthful for every Composer row, so nothing has to re-derive the
     * mode from the type on top of the column (see Package::isGitSourced()).
     *
     * The 2026_08_06 migration backfilled the rows that existed then, but
     * Api\V1\PackageController::store() went on creating Composer packages straight from
     * the validated request, which carries no mode for a type that offers only one — so
     * they landed on the column's DB default, 'publish'. Composer permits nothing but git
     * (PackageSourceMode::allowedFor()), so every such row is a mislabelled git mirror.
     */
    public function up(): void
    {
        DB::table('packages')
            ->where('type', 'composer')
            ->where('source_mode', '!=', 'git')
            ->update(['source_mode' => 'git']);
    }

    /**
     * Deliberately one-way: git is the only mode Composer has ever had, so there is no
     * earlier value worth restoring.
     */
    public function down(): void
    {
        // No inverse. See the note above.
    }
};
