<?php

use App\Enums\SyncStatus;
use App\Events\PackageSyncFailed;
use App\Jobs\SyncPackage;
use App\Models\Package;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;
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

it('records failures in the db and rethrows so the queue can retry', function () {
    $pkg = Package::factory()->create(['repository_url' => 'file:///does/not/exist-'.uniqid()]);

    // Rethrow is intentional: transient failures (network) should be retried.
    expect(fn () => (new SyncPackage($pkg))->handle())->toThrow(RuntimeException::class);

    expect($pkg->fresh()->sync_status)->toBe(SyncStatus::Failed)
        ->and($pkg->fresh()->sync_error)->not->toBeEmpty();
});

it('declares retry with backoff for transient failures', function () {
    $pkg = Package::factory()->create();
    $job = new SyncPackage($pkg);

    // $maxExceptions, not $tries: retryUntil() is set, and the worker ignores attempts()
    // entirely while its window is open — so contention (a release by WithoutOverlapping,
    // which throws nothing) costs no budget while a git failure still does. See the
    // property's docblock, and tests/Unit/SyncTimingRelationsTest.php for the relations.
    expect($job->maxExceptions)->toBe(3)
        ->and($job->backoff())->toBe([60, 300, 900])
        ->and(property_exists($job, 'tries'))->toBeFalse();
});

it('reports the real git failure in the digest when the queue is the one that gives up', function () {
    // The queue can end this job without ever calling handle() again: the retryUntil window
    // closes, and Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts() fails the job with a
    // MaxAttemptsExceededException whose message ("attempted too many times or run too
    // long") says nothing about the repository. Passing that straight into the digest would
    // replace the one sentence an operator can act on with one they cannot.
    Event::fake([PackageSyncFailed::class]);

    $pkg = Package::factory()->create(['repository_url' => 'file:///does/not/exist-'.uniqid()]);
    expect(fn () => (new SyncPackage($pkg))->handle())->toThrow(RuntimeException::class);

    (new SyncPackage($pkg))->failed(new MaxAttemptsExceededException('attempted too many times'));

    Event::assertDispatched(PackageSyncFailed::class, fn (PackageSyncFailed $e) => str_contains($e->error, 'git clone failed'));
});

it('reports the exception itself when the failure is not the queue giving up', function () {
    // The mirror image: an ordinary final failure carries its own message, and must not be
    // silently replaced by whatever happens to be in sync_error from an earlier attempt.
    Event::fake([PackageSyncFailed::class]);

    $pkg = Package::factory()->create(['sync_error' => 'stale error from an earlier attempt']);

    (new SyncPackage($pkg))->failed(new RuntimeException('boom'));

    Event::assertDispatched(PackageSyncFailed::class, fn (PackageSyncFailed $e) => $e->error === 'boom');
});

it('keeps release dates and latest-version stable across re-syncs', function () {
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.FixtureRepo::make()]);
    (new SyncPackage($pkg))->handle();

    $firstReleasedAt = $pkg->versions()->where('version_pretty', 'v1.0.0')->first()->released_at;

    (new SyncPackage($pkg))->handle();
    $pkg->refresh();

    // released_at comes from the commit date, not from now() — stays stable across re-syncs.
    expect($pkg->versions()->where('version_pretty', 'v1.0.0')->first()->released_at->equalTo($firstReleasedAt))->toBeTrue()
        // Description comes from the highest semver version (v1.1.0), not alphabetically.
        ->and($pkg->description)->toBe('Demo package v2');
});

it('skips a tag without composer.json instead of failing the whole package', function () {
    $fixture = FixtureRepo::make();
    // Tag on a commit without composer.json.
    Process::path($fixture)->run('git -c user.email=t@t -c user.name=t commit --allow-empty -m empty')->throw();
    Process::path($fixture)->run('git rm composer.json')->throw();
    Process::path($fixture)->run('git -c user.email=t@t -c user.name=t commit -m "drop composer.json"')->throw();
    Process::path($fixture)->run('git tag v2.0.0')->throw();
    $pkg = Package::factory()->create(['name' => 'acme/demo', 'repository_url' => 'file://'.$fixture]);

    (new SyncPackage($pkg))->handle();

    $pkg->refresh();
    expect($pkg->sync_status)->toBe(SyncStatus::Synced)
        ->and($pkg->versions()->pluck('version_pretty')->all())->toContain('v1.0.0', 'v1.1.0')
        ->and($pkg->versions()->pluck('version_pretty')->all())->not->toContain('v2.0.0');
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
