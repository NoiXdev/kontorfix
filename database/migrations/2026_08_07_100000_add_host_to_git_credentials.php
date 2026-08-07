<?php

use App\Enums\GitProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Binds a stored git credential to the host it was issued for. Without it, any URL an
     * operator submits receives the credential's token as an HTTP Authorization header —
     * so pointing a package or a probe at an attacker-controlled host discloses a secret
     * that is otherwise encrypted, hidden and never readable through the application.
     */
    public function up(): void
    {
        Schema::table('git_credentials', function (Blueprint $table) {
            $table->string('host')->nullable()->after('provider');
        });

        // Backfill: the canonical host of the provider, and for self-hosted ("generic")
        // credentials the host of a repository the credential is already assigned to.
        foreach (GitProvider::cases() as $provider) {
            $host = $provider->defaultHost();
            if ($host !== null) {
                DB::table('git_credentials')->where('provider', $provider->value)->update(['host' => $host]);
            }
        }

        $generic = DB::table('git_credentials')->whereNull('host')->pluck('id');
        foreach ($generic as $id) {
            $url = DB::table('packages')->where('git_credential_id', $id)
                ->whereNotNull('repository_url')->value('repository_url');
            $host = $url !== null ? parse_url((string) $url, PHP_URL_HOST) : null;

            if (is_string($host) && $host !== '') {
                DB::table('git_credentials')->where('id', $id)->update(['host' => strtolower($host)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('git_credentials', function (Blueprint $table) {
            $table->dropColumn('host');
        });
    }
};
