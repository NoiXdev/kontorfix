<?php

use App\Enums\SyncStatus;
use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Support\Facades\Process;
use Tests\Support\FixtureRepo;

it('imports tagged versions with normalized version strings', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);

    (new SyncPackage($pkg))->handle();

    $pkg->refresh();
    expect($pkg->sync_status)->toBe(SyncStatus::Synced)
        ->and($pkg->versions()->pluck('version_pretty')->all())->toContain('v1.0.0', 'v1.1.0')
        ->and($pkg->versions()->where('version_pretty', 'v1.0.0')->first()->version)->toBe('1.0.0.0');
});

it('stores the composer.json metadata and source reference per version', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();

    $v = $pkg->versions()->where('version_pretty', 'v1.1.0')->first();
    expect($v->metadata['require']['php'])->toBe('>=8.3')
        ->and($v->source_reference)->toMatch('/^[0-9a-f]{40}$/');
});

it('is idempotent: re-syncing does not duplicate versions', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();
    (new SyncPackage($pkg))->handle();

    expect($pkg->versions()->count())->toBe(2);
});

it('records failures instead of throwing away the error', function () {
    $pkg = Package::factory()->create(['repository_url' => 'file:///does/not/exist-'.uniqid()]);

    (new SyncPackage($pkg))->handle();

    expect($pkg->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($pkg->fresh()->sync_error)->not->toBeEmpty();
});

it('skips non-version tags', function () {
    $fixture = FixtureRepo::make();
    Process::path($fixture)->run('git tag not-a-version')->throw();
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.$fixture]);

    (new SyncPackage($pkg))->handle();

    expect($pkg->versions()->pluck('version_pretty')->all())->not->toContain('not-a-version');
});

it('records a failure when repository_url is not set', function () {
    $pkg = Package::factory()->create(['repository_url' => null]);

    (new SyncPackage($pkg))->handle();

    expect($pkg->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($pkg->fresh()->sync_error)->not->toBeEmpty();
});
