<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * `OidcUserResolver` links an incoming identity to an existing account whenever the
 * provider asserts a matching, verified email — trusting every configured IdP equally.
 * A second, less trustworthy provider can therefore claim `email_verified: true` for
 * someone else's address and be linked to their (unprivileged) account. This column lets
 * an operator mark, per provider, whether that trust is warranted; the resolver now
 * refuses the auto-link when it is not.
 *
 * The column defaults to `false` — new providers are untrusted until an operator opts
 * in — but every row that already exists at migration time is backfilled to `true`.
 * That backfill is deliberate: these providers were already relied on for linking under
 * the old, unconditional behaviour, and an instance whose users sign in only through SSO
 * would otherwise lock everyone out until a super-admin found and flipped the new
 * setting. Flipping every provider to `false` on upgrade is exactly the hardening
 * mistake this product shipped in v0.7.0 — a fix that ignored pre-existing state. The
 * backfill is logged so an operator learns the setting exists and can revisit it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oidc_providers', function (Blueprint $table) {
            $table->boolean('trusts_email_claim')->default(false)->after('allow_registration');
        });

        $existing = DB::table('oidc_providers')->get(['id', 'name', 'slug']);

        if ($existing->isEmpty()) {
            return;
        }

        DB::table('oidc_providers')->update(['trusts_email_claim' => true]);

        Log::warning('Existing OIDC providers were marked as trusting the email claim during upgrade. Review whether each one should still be able to auto-link accounts by email.', [
            'providers' => $existing->map(fn ($provider) => [
                'id' => $provider->id,
                'slug' => $provider->slug,
                'name' => $provider->name,
            ])->all(),
        ]);
    }

    public function down(): void
    {
        Schema::table('oidc_providers', function (Blueprint $table) {
            $table->dropColumn('trusts_email_claim');
        });
    }
};
