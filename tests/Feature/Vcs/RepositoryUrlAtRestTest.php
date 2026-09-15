<?php

// `packages.repository_url` legitimately carries a git credential — it is the only way to
// authenticate a remote when the dedicated `repository_token` was skipped, and
// App\Support\CredentialUrl documents it as a supported carrier. It was the one such column
// stored in the clear, while `repository_token` right beside it has always been encrypted.
//
// The audit's L4 suggested moving the credential into that other column instead. That would
// have broken authentication silently: gitAuth()'s inline branch always answers
// GitProvider::GitHub with a null username, so a migrated `oauth2:…@gitlab` or
// `x-token-auth:…@bitbucket` would start authenticating as `x-access-token` at the next
// sync. Encrypting the column closes the same gap without touching auth semantics.

use App\Enums\PackageType;
use App\Models\Package;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('reads back exactly what was written', function () {
    $url = 'https://x-access-token:ghp_secret_value@github.com/acme/demo.git';
    $package = Package::factory()->create(['type' => PackageType::Composer, 'repository_url' => $url]);

    expect($package->fresh()->repository_url)->toBe($url);
});

it('never stores the credential in the clear', function () {
    $package = Package::factory()->create([
        'type' => PackageType::Composer,
        'repository_url' => 'https://x-access-token:ghp_secret_value@github.com/acme/demo.git',
    ]);

    $raw = DB::table('packages')->where('id', $package->id)->value('repository_url');

    expect($raw)->not->toContain('ghp_secret_value')
        ->and($raw)->not->toContain('github.com');
});

it('holds a ciphertext longer than the old column allowed', function () {
    // The column was varchar(255) and a short repository URL encrypts to well over 300
    // characters, so widening it is part of the change rather than a nicety — without it
    // the first save truncates or throws.
    expect(Schema::getColumnType('packages', 'repository_url'))->toBe('text');
});

it('still answers whereNotNull, which the webhook matcher and the resync command rely on', function () {
    // Neither ever matches on the VALUE in SQL — RepoUrlMatcher fetches the rows and
    // compares in PHP — so encrypting the column costs them nothing. This pins that,
    // because a future value-level query would silently match ciphertext.
    Package::factory()->create(['type' => PackageType::Composer, 'repository_url' => 'https://github.com/acme/demo.git']);
    Package::factory()->create(['type' => PackageType::Composer, 'repository_url' => null]);

    expect(Package::whereNotNull('repository_url')->count())->toBe(1);
});

it('leaves a null url null rather than encrypting an empty string', function () {
    $package = Package::factory()->create(['type' => PackageType::Composer, 'repository_url' => null]);

    expect($package->fresh()->repository_url)->toBeNull()
        ->and(DB::table('packages')->where('id', $package->id)->value('repository_url'))->toBeNull();
});
