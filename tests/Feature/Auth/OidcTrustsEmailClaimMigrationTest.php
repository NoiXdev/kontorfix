<?php

// The `trusts_email_claim` column defaults to `false` — new providers are untrusted until
// an operator opts in — but every row that already existed at migration time must be
// backfilled to `true`. Flipping every provider to `false` on upgrade would lock out any
// instance whose users sign in only through SSO until a super-admin found the new setting;
// this is the same class of mistake as v0.7.0's hardening-that-ignored-pre-existing-state.

use App\Models\OidcProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

function runTrustsEmailClaimMigration(): object
{
    return require database_path('migrations/2026_08_28_120000_add_trusts_email_claim_to_oidc_providers.php');
}

it('backfills a provider that already existed to trusted, and logs which ones', function () {
    $existing = OidcProvider::factory()->create(['name' => 'Legacy Authentik', 'slug' => 'legacy-authentik']);

    // Simulate the pre-migration schema: this row predates the `trusts_email_claim` column.
    Schema::table('oidc_providers', fn (Blueprint $table) => $table->dropColumn('trusts_email_claim'));

    Log::spy();

    runTrustsEmailClaimMigration()->up();

    expect((bool) DB::table('oidc_providers')->where('id', $existing->id)->value('trusts_email_claim'))->toBeTrue();

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => str_contains($message, 'trusting the email claim')
            && collect($context['providers'])->contains(fn ($p) => $p['slug'] === 'legacy-authentik'));
});

it('lets a newly created provider default to untrusted', function () {
    $attrs = OidcProvider::factory()->raw();
    unset($attrs['trusts_email_claim']);

    $fresh = OidcProvider::create($attrs);

    expect($fresh->fresh()->trusts_email_claim)->toBeFalse();
});

it('does not log when there are no pre-existing providers to backfill', function () {
    Schema::table('oidc_providers', fn (Blueprint $table) => $table->dropColumn('trusts_email_claim'));

    Log::spy();

    runTrustsEmailClaimMigration()->up();

    Log::shouldNotHaveReceived('warning');
});
