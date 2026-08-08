<?php

// The `host` backfill decides, once and irreversibly for every pre-existing credential,
// which host is allowed to receive that organization's git PAT. It must never take that
// answer from `packages.repository_url`: under the pre-upgrade code any maintainer could
// point a package at a host they controlled, so a URL-derived backfill would bless an
// attacker-chosen host — the exact leak the host binding was written to close.

use App\Enums\GitProvider;
use App\Models\GitCredential;
use App\Models\Organization;
use App\Models\Package;
use Illuminate\Support\Facades\Schema;

/** Re-runs the backfill against rows that look like a pre-upgrade database. */
function runHostBackfill(): void
{
    Schema::table('git_credentials', function ($table) {
        $table->dropColumn('host');
    });

    $migration = require database_path('migrations/2026_08_07_100000_add_host_to_git_credentials.php');
    $migration->up();
}

it('never derives a generic credentials host from a package repository url', function () {
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::Generic, 'host' => 'git.acme.test']);
    Package::factory()->create([
        'git_credential_id' => $cred->id,
        'repository_url' => 'https://evil.example/acme/private.git',
    ]);

    runHostBackfill();

    $fresh = GitCredential::find($cred->id);
    expect($fresh->host)->toBeNull()
        ->and($fresh->allowedHost())->toBeNull()
        ->and($fresh->permits('https://evil.example/acme/private.git'))->toBeFalse();
});

it('backfills the canonical host for a provider credential', function () {
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub, 'host' => null]);
    Package::factory()->create([
        'git_credential_id' => $cred->id,
        'repository_url' => 'https://github.com/acme/private.git',
    ]);

    runHostBackfill();

    expect(GitCredential::find($cred->id)->host)->toBe('github.com');
});

it('leaves the host unset when an assigned package contradicts the canonical one', function () {
    // A self-hosted GitHub Enterprise credential, or a credential a maintainer already
    // pointed elsewhere. Either way the migration must not decide: a package URL may
    // veto the canonical host but must never choose a different one.
    $org = Organization::factory()->create();
    $cred = GitCredential::factory()->for($org)->create(['provider' => GitProvider::GitHub, 'host' => null]);
    Package::factory()->create([
        'git_credential_id' => $cred->id,
        'repository_url' => 'https://ghe.acme.test/acme/private.git',
    ]);

    runHostBackfill();

    $fresh = GitCredential::find($cred->id);
    expect($fresh->host)->toBeNull()
        ->and($fresh->permits('https://ghe.acme.test/acme/private.git'))->toBeFalse();
});
