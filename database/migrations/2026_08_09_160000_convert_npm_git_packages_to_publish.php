<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * npm packages can no longer use the git-mirror source mode: what `npm publish`
     * uploads is a built artifact, not the repository tree, so a mirror produced a
     * package with the right name and the wrong contents.
     *
     * Only `source_mode` changes. `repository_url` and `git_credential_id` are inert for
     * a publish package and are kept, because discarding them destroys information the
     * operator may still want. Imported versions are kept too — they simply stop being
     * refreshed.
     */
    public function up(): void
    {
        DB::table('packages')
            ->where('type', 'npm')
            ->where('source_mode', 'git')
            ->update(['source_mode' => 'publish']);
    }

    /**
     * Deliberately one-way. An npm publish package that records a repository_url is
     * indistinguishable from one that was never a mirror — a publish package may
     * legitimately keep its repository for display — so restoring by inference would
     * turn unrelated packages into broken mirrors.
     */
    public function down(): void
    {
        // No safe inverse. See the note above.
    }
};
