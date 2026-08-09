<?php

use App\Support\RepositoryAuthority;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Binds the inline `packages.repository_token` to one authority, the way a managed
 * `GitCredential` is bound by `git_credentials.host`.
 *
 * Without a recorded destination there is nothing for `Package::gitAuth()` to check at the
 * sink: the only guard was the write-time one in the console, and any other writer of
 * `repository_url` — a super-admin, a future API path, a direct edit — silently retargeted
 * the token with it.
 *
 * The backfill stamps every existing token with the authority of the URL it currently sits
 * next to, so no package that syncs today stops syncing: the pair already agrees, and the
 * check only ever refuses a LATER divergence. A row whose URL has no parseable host keeps a
 * null binding, which `gitAuth()` treats as "unknown destination" and therefore refuses —
 * such a package could not have been syncing anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->string('repository_token_host')->nullable()->after('repository_token');
        });

        DB::table('packages')
            ->whereNotNull('repository_token')
            ->orderBy('id')
            ->select('id', 'repository_url')
            ->chunk(200, function ($rows) {
                foreach ($rows as $row) {
                    $authority = RepositoryAuthority::of($row->repository_url);
                    if ($authority === null) {
                        continue;
                    }

                    DB::table('packages')->where('id', $row->id)->update(['repository_token_host' => $authority]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('repository_token_host');
        });
    }
};
