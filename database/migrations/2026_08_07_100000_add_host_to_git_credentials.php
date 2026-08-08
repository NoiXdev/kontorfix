<?php

use App\Enums\GitProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Backfill: the canonical host of the provider, and nothing else.
        //
        // `packages.repository_url` must never *choose* a host. Under the pre-upgrade
        // code any maintainer could point a package at a host they controlled and attach
        // an arbitrary credential to it, so deriving the binding from that URL would
        // bless an attacker-chosen host — precisely the disclosure this column exists to
        // prevent, carried across the upgrade for exactly the rows that were exposed.
        //
        // It may, however, *veto*: where an assigned package points somewhere other than
        // the provider's canonical host, the credential is likely a self-hosted GitLab or
        // GitHub Enterprise one and the migration has no safe way to know its host. Those
        // rows are left unset and the operator is told, because a maintainer who can only
        // cause a fail-closed state cannot use the veto to gain anything.
        foreach (GitProvider::cases() as $provider) {
            $canonical = $provider->defaultHost();
            if ($canonical === null) {
                // Generic: the only candidate would be the package URL. Left null, which
                // `allowedHost()`/`permits()` treat as "unusable until an operator names
                // the host", paired with re-entering the token (GitCredentialController).
                continue;
            }

            foreach (DB::table('git_credentials')->where('provider', $provider->value)->pluck('id') as $id) {
                $contradicting = $this->foreignHosts((string) $id, $canonical);

                if ($contradicting === []) {
                    DB::table('git_credentials')->where('id', $id)->update(['host' => $canonical]);

                    continue;
                }

                Log::warning('Git credential left without a host binding: assigned packages point elsewhere.', [
                    'git_credential_id' => $id,
                    'canonical_host' => $canonical,
                    'package_hosts' => $contradicting,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('git_credentials', function (Blueprint $table) {
            $table->dropColumn('host');
        });
    }

    /**
     * Hosts of the packages assigned to this credential that are not the canonical one.
     *
     * @return list<string>
     */
    private function foreignHosts(string $credentialId, string $canonical): array
    {
        $hosts = [];

        foreach (DB::table('packages')->where('git_credential_id', $credentialId)
            ->whereNotNull('repository_url')->pluck('repository_url') as $url) {
            $host = parse_url((string) $url, PHP_URL_HOST);

            if (is_string($host) && $host !== '' && strtolower($host) !== $canonical) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }
};
