<?php

use App\Jobs\SyncPackage;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\Queue;
use Symfony\Component\Yaml\Yaml;

/**
 * The git mirror lock is not held together by any single number. It is held together by
 * relations between numbers that live in six different files — a class constant in
 * app/Services/Vcs, a job property in app/Jobs, two config values under config/, a
 * supervisor setting in config/horizon.php and a container setting in docker/compose.yaml —
 * and every regression this branch has had to repair so far was a broken relation, not a
 * wrong literal:
 *
 * - a lock TTL shaved to 330s while the work under it could take 315s, so the TTL expired
 *   mid-clone and a waiter deleted a live clone;
 * - a lock wait of 15s spent inside a job the worker killed at 60s, so contention became a
 *   failure digest;
 * - then the same 15s wait applied to the web dist path, which has no retry at all, so
 *   contention became an HTTP 500;
 * - and then the *fix* for that — the queue's 330s wait, applied to the web path — turned
 *   contention into a parked FrankenPHP thread, which at any concurrency takes the whole
 *   registry (including /up) down with it.
 *
 * Asserting the literals (`expect(LOCK_TTL)->toBe(900)`) would restate the source and catch
 * none of that. Every assertion below is an inequality between values read from different
 * files, and two habits are deliberate:
 *
 * - The job's timeout, retry window and exception budget are read out of the **queue
 *   payload**, not off the properties. That is the only place the worker itself looks
 *   (`Illuminate\Queue\Jobs\Job::timeout()` reads `payload()['timeout']`), and
 *   `Illuminate\Queue\Queue::createObjectPayload()` resolves them from three possible
 *   locations — the property, a `#[Timeout]`/`#[MaxExceptions]` attribute, or neither. A
 *   test that reads `$job->timeout` models one of those three and silently stops covering
 *   the code once someone uses another.
 * - Nothing here asserts that a constant equals its own definition, and nothing asserts a
 *   relation between two numbers computed in the same expression. Those cannot fail. What
 *   binds the constants to the commands they are supposed to govern is
 *   tests/Unit/GitRepositoryTest.php, which fakes the process runner and reads the timeout
 *   off the git invocation that actually ran.
 */
function syncPackageJob(): SyncPackage
{
    return new SyncPackage((new Package)->forceFill(['id' => 'timing-relations']));
}

/**
 * The queue payload Laravel would push for this job — the same array the worker later reads
 * `timeout`, `retryUntil` and `maxExceptions` out of.
 *
 * `createPayload()` is protected on the abstract base every queue driver extends, so an
 * anonymous subclass is the way to reach it without reimplementing the resolution rules
 * this test exists to follow rather than to duplicate.
 *
 * @return array<string, mixed>
 */
function syncPayload(): array
{
    $queue = new class extends Queue
    {
        /** @return array<string, mixed> */
        public function payloadFor(object $job): array
        {
            return json_decode($this->createPayload($job, 'default'), true);
        }
    };

    return $queue->payloadFor(syncPackageJob());
}

/**
 * The app container's healthcheck, read from the file the deployment actually ships.
 *
 * @return array{interval: int, timeout: int, retries: int}
 */
function appHealthcheck(): array
{
    $compose = Yaml::parseFile(base_path('docker/compose.yaml'));
    $health = $compose['services']['app']['healthcheck'];

    return [
        'interval' => (int) rtrim((string) $health['interval'], 's'),
        'timeout' => (int) rtrim((string) $health['timeout'], 's'),
        'retries' => (int) $health['retries'],
    ];
}

it('keeps the mirror lock TTL above the longest a live holder can hold it', function () {
    // A holder that acquires the lock immediately spends its whole job timeout inside the
    // locked region, so that — not WORST_CASE_WORK — is what the TTL has to cover. The old
    // form of this assertion (TTL >= WORST_CASE_WORK) was satisfied by the round-2 defect
    // it claimed to catch: 330 >= 315 is true.
    //
    // Equality is the intended value, not a coincidence: at that instant the worker's own
    // alarm fires, so the TTL can only ever lapse on a process that is already being killed.
    expect(GitRepository::LOCK_TTL)->toBeGreaterThanOrEqual((int) syncPayload()['timeout']);
});

it('waits for the mirror lock at least as long as a live holder can legitimately take, on the queue path', function () {
    // This is what makes the abort honest for the queue caller. Below WORST_CASE_WORK,
    // running out of wait mostly means "the holder is mid-clone" — and the correct answer to
    // that, for a caller that owns its worker process, is to keep waiting.
    expect((int) config('kontorfix.mirror_lock_wait'))
        ->toBeGreaterThanOrEqual(GitRepository::WORST_CASE_WORK);
});

it('keeps the web caller off the queue caller\'s wait', function () {
    // The regression this file's docblock ends with. A synchronous request blocked on the
    // mirror lock holds one thread of the FrankenPHP pool, and that pool also answers the
    // container healthcheck. Worst case every thread is parked for the whole web wait, so
    // /up cannot be answered for that long — and the container must survive it without
    // crossing its failure threshold.
    $health = appHealthcheck();
    $webWait = (int) config('kontorfix.mirror_lock_wait_web');

    expect($webWait + $health['timeout'])
        ->toBeLessThanOrEqual($health['interval'] * $health['retries']);

    // And the two waits must not be quietly re-unified: the queue caller's value is sized
    // to outlast a clone, which is precisely what the web caller must not do.
    expect($webWait)->toBeLessThan((int) config('kontorfix.mirror_lock_wait'));
});

it('lets a sync job outlive its own lock wait plus the work after it', function () {
    // The property everything else rests on: the queue worker's kill is a pcntl_alarm
    // handler calling exit(), so `finally` never runs and a job killed inside sync() leaves
    // the mirror lock held for its full TTL. As long as this holds, the alarm can only land
    // outside the locked region.
    expect((int) syncPayload()['timeout'])->toBeGreaterThanOrEqual(
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
            ->toBeGreaterThan((int) syncPayload()['timeout']);
    }
});

it('keeps every Horizon supervisor from hard-stopping a worker that is still syncing', function () {
    // A supervisor's `timeout` is not only the fallback worker alarm — a job's own $timeout
    // wins there. It is also what ProcessPool::stopTerminatingProcessesThatAreHanging()
    // waits after SIGTERM before hard-stopping a worker (autoscaler scale-down slices the
    // OLDEST processes, so this lands on a worker mid-clone), and what
    // MasterSupervisor::terminate() waits before exit()ing. Neither consults the job.
    $timeout = (int) syncPayload()['timeout'];

    // Every scope kept separate. Merging them by supervisor name loses the entry that
    // actually carries the value: `environments.*.supervisor-1` has the same key as
    // `defaults.supervisor-1` and declares no timeout, so a merge silently replaced the one
    // setting under test with one that is skipped — and the assertion passed vacuously.
    $scopes = collect(['defaults' => config('horizon.defaults')])
        ->merge(collect(config('horizon.environments'))->mapWithKeys(
            fn (array $group, string $env) => ["environments.{$env}" => $group],
        ));

    $checked = 0;

    foreach ($scopes as $scope => $group) {
        foreach ($group as $name => $options) {
            if (! array_key_exists('timeout', $options)) {
                continue; // Inherits from defaults, which is checked in its own scope.
            }

            $checked++;
            expect((int) $options['timeout'])
                ->toBeGreaterThanOrEqual($timeout, "horizon [{$scope}.{$name}] timeout");
        }
    }

    // A supervisor timeout has to be declared somewhere: without this, deleting the setting
    // — or restructuring the config so the loop never reaches it — reads as a pass.
    expect($checked)->toBeGreaterThan(0);
});

it('gives the worker container long enough to stop without killing a sync', function () {
    // Docker's default stop grace period is 10s, after which PID 1 — `php artisan horizon` —
    // is SIGKILLed. That is a routine Portainer redeploy killing a `git clone --mirror` mid
    // way and leaving the mirror lock held for its full TTL.
    $compose = Yaml::parseFile(base_path('docker/compose.yaml'));
    $grace = $compose['services']['worker']['stop_grace_period'] ?? null;

    expect($grace)->not->toBeNull()
        ->and((int) rtrim((string) $grace, 's'))
        ->toBeGreaterThanOrEqual((int) syncPayload()['timeout']);
});

it('keeps the per-package overlap lock alive for as long as the job it guards', function () {
    $middleware = syncPackageJob()->middleware()[0];

    expect($middleware)->toBeInstanceOf(WithoutOverlapping::class);
    // An expiry below the job timeout lapses mid-job and lets a second SyncPackage for the
    // same package start alongside the first. The expiry is computed from the property, the
    // timeout is read from the payload — so this also fails if the two ever stop agreeing
    // (a `#[Timeout]` attribute without the property, for instance).
    expect((int) $middleware->expiresAfter)->toBeGreaterThanOrEqual((int) syncPayload()['timeout']);
});

it('gives a sync parked behind the overlap lock a window that outlasts the holder', function () {
    // Every reservation that finds the overlap lock held used to burn one of $tries, so a
    // blocked duplicate could exhaust its attempts on contention alone and fire
    // PackageSyncFailed for a package whose only problem was that it was already syncing.
    // retryUntil is what removes that: Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()
    // ignores attempts() entirely while the window is open. For that to be worth anything
    // the window has to outlast a holder that runs to its own timeout.
    $payload = syncPayload();

    expect($payload['retryUntil'])->not->toBeNull();
    expect($payload['retryUntil'] - now()->getTimestamp())
        ->toBeGreaterThanOrEqual((int) $payload['timeout']);

    // And the count-based budget has to be the one that survives contention: maxExceptions
    // counts thrown exceptions, so a release costs nothing, while maxTries would have been
    // ignored by the worker anyway once retryUntil is set. A payload carrying maxTries here
    // is a property that reads like a retry budget and does nothing.
    expect($payload['maxExceptions'])->toBeGreaterThan(0)
        ->and($payload['maxTries'])->toBeNull();
});

it('makes every queued job declare its own timeout', function () {
    // The consequence of raising the supervisor timeout to SyncPackage's 900s:
    // Worker::timeoutForJob() hands that value to any job that declares none, so a job added
    // without a $timeout silently inherits a fifteen-minute worker alarm instead of the
    // minute it would have got before. That is invisible until something hangs, which is
    // exactly the kind of drift this file exists to refuse.
    $jobs = collect(glob(app_path('Jobs/*.php')))
        ->map(fn (string $file) => 'App\\Jobs\\'.basename($file, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, ShouldQueue::class));

    expect($jobs)->not->toBeEmpty();

    foreach ($jobs as $class) {
        expect(property_exists($class, 'timeout'))->toBeTrue("{$class} declares no \$timeout");
    }
});
