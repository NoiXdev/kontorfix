<?php

use App\Jobs\SyncPackage;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use App\Services\Vcs\MirrorState;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * The git mirror lock is not held together by any single number. It is held together by
 * relations between numbers that live in four different files — a class constant in
 * app/Services/Vcs, a job property in app/Jobs, and two config values under config/ — and
 * every regression this branch has had to repair so far was a broken relation, not a
 * wrong literal:
 *
 * - a lock TTL shaved to 330s while the work under it could take 315s, so the TTL expired
 *   mid-clone and a waiter deleted a live clone;
 * - a lock wait of 15s spent inside a job the worker killed at 60s, so contention became a
 *   failure digest;
 * - and then the same 15s wait applied to the web dist path, which has no retry at all, so
 *   contention became an HTTP 500.
 *
 * Asserting the literals (`expect(LOCK_TTL)->toBe(900)`) would restate the source and
 * catch none of that. Asserting the relations catches all three, and keeps catching them
 * when someone raises one number for a good reason and forgets its neighbour. Every
 * assertion below is therefore an inequality between values read from different files.
 */
function syncPackageJob(): SyncPackage
{
    return new SyncPackage((new Package)->forceFill(['id' => 'timing-relations']));
}

/**
 * The effective timeout Laravel will actually apply, resolved the same way
 * Illuminate\Queue\Worker::timeoutForJob() does: a job's own $timeout wins, and only a job
 * without one falls back to the worker's. Written this way on purpose — the invariant is
 * "SyncPackage may run long enough", not "SyncPackage has a $timeout property", so moving
 * the value between the job and the supervisor must not break the test, while deleting it
 * from both must.
 */
function effectiveSyncTimeout(): int
{
    return syncPackageJob()->timeout ?? (int) config('horizon.defaults.supervisor-1.timeout');
}

it('keeps the mirror lock TTL above the work performed under it', function () {
    // The round-2 defect: a TTL that can expire while its holder is still cloning hands a
    // waiter the lock — and the waiter's first act is a recursive delete of the directory
    // being cloned into.
    expect(GitRepository::LOCK_TTL)->toBeGreaterThanOrEqual(GitRepository::WORST_CASE_WORK);

    // And WORST_CASE_WORK has to keep meaning what its name says: the two bounded steps
    // taken while the lock is held, read from where they are actually applied.
    expect(GitRepository::WORST_CASE_WORK)
        ->toBe(MirrorState::CHECK_TIMEOUT + GitRepository::CLONE_TIMEOUT);
});

it('waits for the mirror lock at least as long as a live holder can legitimately take', function () {
    // This is what makes the abort honest. Below WORST_CASE_WORK, running out of wait
    // mostly means "the holder is mid-clone" — and the correct answer to that is to keep
    // waiting, not to give up, because the one caller with no retry behind it
    // (ComposerController::dist()) turns the abort into an HTTP 500 for `composer install`.
    expect((int) config('kontorfix.mirror_lock_wait'))
        ->toBeGreaterThanOrEqual(GitRepository::WORST_CASE_WORK);
});

it('lets a sync job outlive its own lock wait plus the work after it', function () {
    // The property everything else rests on: the queue worker's kill is a pcntl_alarm
    // handler calling exit(), so `finally` never runs and a job killed inside sync() leaves
    // the mirror lock held for its full TTL. As long as this holds, the kill can only land
    // outside the locked region.
    expect(effectiveSyncTimeout())->toBeGreaterThanOrEqual(
        (int) config('kontorfix.mirror_lock_wait') + GitRepository::WORST_CASE_WORK
    );
});

it('never lets the queue re-dispatch a sync that is still running', function () {
    // retry_after is when the queue decides a reserved job is lost. Below the job's own
    // timeout it decides that about a job that is merely still cloning, and a second worker
    // starts the same sync in parallel — the exact concurrency the mirror lock exists to
    // rule out, reintroduced one layer above it.
    // Both connections, because which one is live is an env decision (database in a plain
    // install, redis in the shipped Docker/Horizon setup) and the job is the same either way.
    foreach (['database', 'redis'] as $connection) {
        expect((int) config("queue.connections.{$connection}.retry_after"))
            ->toBeGreaterThan(effectiveSyncTimeout());
    }
});

it('keeps the per-package overlap lock alive for as long as the job it guards', function () {
    $middleware = syncPackageJob()->middleware()[0];

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class);
    // An expiry below the job timeout lapses mid-job and lets a second SyncPackage for the
    // same package start alongside the first.
    expect((int) $middleware->expiresAfter)->toBeGreaterThanOrEqual(effectiveSyncTimeout());
});

it('gives a sync parked behind the overlap lock an attempt left once the holder finishes', function () {
    $job = syncPackageJob();
    $middleware = $job->middleware()[0];

    // Every reservation that finds the overlap lock held burns one of $tries, so a blocked
    // duplicate has (tries - 1) waiting periods before the queue fails it outright — and a
    // failure here fires PackageSyncFailed for a package whose only problem was that it was
    // already syncing. Spacing the retries so the last one lands after the holder's timeout
    // is what keeps contention from being reported as a package failure.
    expect((int) $middleware->releaseAfter * ($job->tries - 1))
        ->toBeGreaterThanOrEqual(effectiveSyncTimeout());
});
