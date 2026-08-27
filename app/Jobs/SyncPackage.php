<?php

namespace App\Jobs;

use App\Enums\PackageSourceMode;
use App\Enums\SyncStatus;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use App\Services\Vcs\GitSourceImporter;
use App\Services\Vcs\ReadmeLocator;
use App\Services\Vcs\ReadmeRenderer;
use Composer\Semver\Semver;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPackage implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds this job may run before the worker kills it.
     *
     * A const as well as a property because config/horizon.php reads it: the supervisor
     * timeout has to be at least this value (see below), and a relation that two files
     * restate as literals is a relation that drifts.
     */
    public const TIMEOUT = 900;

    /** Seconds a job parked behind the per-package overlap lock waits before trying again. */
    private const RELEASE_AFTER = 30;

    /**
     * Seconds this job may run before the worker kills it.
     *
     * Declared here rather than left to the supervisor because the supervisor's value is
     * far below the work this job actually performs, and the kill is a `pcntl_alarm`
     * handler that calls `exit()`: `finally` blocks do not unwind, so every lock the job
     * holds stays held until its TTL runs out. GitRepository's mirror lock has a TTL of
     * 900s, and `git clone --mirror` is allowed 300s — so with a 60s worker timeout, *any*
     * repository slower than a minute to clone ended every attempt with a killed holder and
     * a mirror wedged for 15 minutes.
     *
     * The value is derived, not picked. Worst case before the lock is released:
     *
     *     mirror_lock_wait (330) + GitRepository::WORST_CASE_WORK (15 + 300) = 645s
     *
     * so 900s keeps the `pcntl_alarm` kill outside the *bounded* part of the locked region
     * and leaves 255s for the unbounded directory delete on the Repairable path plus the
     * version import and README render. Only the last two run after sync() has released the
     * lock, which is why GitRepository::LOCK_TTL names this budget as the authoritative one
     * for the delete.
     *
     * "Outside the locked region" is therefore a property of the bounded steps, not of the
     * whole method: a delete that runs longer than those 255s does take the alarm inside the
     * lock, and nothing here can prevent that — a recursive unlink of a half-written clone
     * on an overlay filesystem has no timeout to give it. What holds unconditionally is the
     * relation asserted in the tests, LOCK_TTL >= this value: the lock cannot lapse under a
     * holder the worker has not already killed.
     *
     * **What this property does and does not guarantee.** `Worker::timeoutForJob()` does
     * prefer it over the supervisor's value, so the alarm is set to 900. That is the only
     * kill path it controls. Four others ignore it entirely, and every one of them had to
     * be dealt with elsewhere:
     *
     * - `ProcessPool::stopTerminatingProcessesThatAreHanging()` hard-stops a worker marked
     *   for termination `supervisor.timeout` seconds after SIGTERM. Fixed by raising that
     *   supervisor value to self::TIMEOUT in config/horizon.php — the thing the previous
     *   version of this comment said was "not a substitute", which was wrong.
     * - The Horizon autoscaler: `packages:resync` runs hourly, the pool scales up, and once
     *   the backlog drains `ProcessPool::scaleDown()` slices the *oldest* processes, not
     *   the idle ones — SIGTERM to a worker mid-clone, then the hard stop above. Same fix.
     * - `MasterSupervisor::terminate()` waits at most `longestActiveTimeout()` (the largest
     *   supervisor timeout) before calling `exit()`. Same fix.
     * - Docker's stop grace period on the worker container, which defaults to 10s: a
     *   redeploy SIGKILLs a running sync ten seconds in. Fixed with an explicit
     *   `stop_grace_period` in docker/compose.yaml.
     *
     * Even with all four addressed, SIGKILL, an OOM kill and host failure remain, and no
     * configuration removes them. This job is therefore written to survive losing its lock
     * (the sync is idempotent, and LOCK_TTL bounds the damage), not on the premise that it
     * cannot happen.
     *
     * Two other numbers are pinned to this one and move with it (see
     * tests/Unit/SyncTimingRelationsTest.php, which reads the value out of the queue
     * payload — the same place the worker reads it from — rather than off this property):
     *
     * - `queue.connections.*.retry_after` must exceed it, or the queue hands the same job
     *   to a second worker while the first is still cloning.
     * - `WithoutOverlapping::expireAfter` must be at least it, or the per-package lock
     *   lapses mid-job and two syncs of one package run side by side.
     */
    public int $timeout = self::TIMEOUT;

    /**
     * Failures that count, as opposed to attempts that count.
     *
     * There is deliberately no `$tries`. `retryUntil()` below is set, and
     * `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` ignores `attempts()` entirely
     * whenever it is — so a `$tries` property here would be inert, and an inert property
     * that reads like a retry budget is worse than none.
     *
     * `$maxExceptions` is the budget that survives, and it is the one that was wanted all
     * along: it counts *thrown exceptions*, so a release by WithoutOverlapping (contention,
     * nobody's fault) is free, while a git failure is not. Three exceptions with the
     * backoff below fails the job at roughly t+360s, which is exactly where `$tries = 3`
     * put it — the digest an operator sees for a genuinely broken repository is unchanged.
     */
    public int $maxExceptions = 3;

    public function __construct(public Package $package) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Ceiling on this job's whole lifetime, and the instrument that decouples contention
     * from failure.
     *
     * `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` returns early while an
     * unexpired `retryUntil` is set and does not look at `attempts()` at all, so a
     * reservation that finds the overlap lock held and releases again costs nothing. That
     * replaces the previous arrangement, where `releaseAfter` had to be `$timeout /
     * ($tries - 1)` so that the last of three reservations would land after the holder's
     * timeout. Two things were wrong with it: it measured from the wrong clock (it assumed
     * the holder started no later than the blocked job's first reservation, which three
     * competing dispatches — the hourly resync, a webhook push and a manual Resync — break),
     * and it made every duplicate wait 450s even when the holder finished in five seconds.
     *
     * The window is derived from the worst case it has to survive: each of the
     * `$maxExceptions` executions may be preceded by a full holder run ($timeout) and by
     * its own backoff delay.
     *
     *     3 × 900 + (60 + 300 + 900) = 3960s
     *
     * Being generous is close to free — the only job that reaches the end of the window is
     * one that spent it entirely in contention, and it releases every 30s while it waits.
     * A genuinely broken repository never gets there: `$maxExceptions` fails it first.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->maxExceptions * $this->timeout + array_sum($this->backoff()));
    }

    /**
     * `releaseAfter` is a politeness delay again, and can be, because a release no longer
     * burns anything (see retryUntil()). 30s is short enough that a duplicate parked behind
     * a five-second holder is on its way five seconds later, and long enough that a job
     * parked behind a 900s holder costs ~30 reservations rather than thousands.
     *
     * `expireAfter` is the mirror image — the ceiling on how long a killed worker can wedge
     * a package — and must cover a job that runs to `$timeout`.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->package->id))
            ->releaseAfter(self::RELEASE_AFTER)
            ->expireAfter($this->timeout)];
    }

    public function handle(): void
    {
        if ($this->package->repository_url === null) {
            $this->markFailed('Für dieses Paket ist keine Repository-URL hinterlegt — im Tab „Quelle“ nachtragen, dann erneut synchronisieren.');

            return; // Configuration error — retrying makes no sense
        }

        // Not every dispatch site checks isGitSourced() before queuing this job (the
        // packages:resync command and incoming webhooks dispatch on repository_url alone),
        // and a publish-mode package may legitimately carry a repository_url purely for
        // reference — npm publish uploads a tarball, not the tree. Attempting a git sync
        // for a package that was never meant to be git-synced is a configuration error,
        // not a transient one, so it is failed the same way rather than left to clone.
        if (! $this->package->isGitSourced()) {
            // The remedy must not recommend a mode the type cannot actually use: npm's
            // allowedFor() is [Publish] only, so "switch to Git-Mirror" would send an
            // operator straight into a validation error on both create paths. Only offer
            // that clause when the type genuinely permits Git.
            $canMirror = in_array(PackageSourceMode::Git, PackageSourceMode::allowedFor($this->package->type), true);

            $this->markFailed(sprintf(
                'Paket ist nicht git-basiert (Quellmodus „%s“) — ein Git-Sync ist hierfür nicht vorgesehen. %s',
                $this->package->source_mode->label(),
                $canMirror
                    ? 'Repository-URL entfernen, falls sie nur zur Referenz dient, oder den Quellmodus auf Git-Mirror stellen, falls das Paket tatsächlich gespiegelt werden soll.'
                    : 'Repository-URL entfernen, falls sie nur zur Referenz dient — dieser Pakettyp kann nicht gespiegelt werden.',
            ));

            return; // Configuration error — retrying makes no sense
        }

        // A row can only reach this state by predating the rule that npm is publish-only.
        // Fail it the same way as any other configuration error: retrying cannot help.
        if (! in_array($this->package->source_mode, PackageSourceMode::allowedFor($this->package->type), true)) {
            $this->markFailed(sprintf(
                'Der Quellmodus „%s“ ist für %s-Pakete nicht mehr zulässig — Paket auf den Quellmodus „Publish“ umstellen.',
                $this->package->source_mode->label(),
                $this->package->type->value,
            ));

            return; // Configuration error — retrying makes no sense
        }

        $this->package->update(['sync_status' => SyncStatus::Syncing]);

        try {
            $auth = $this->package->gitAuth();
            $repo = new GitRepository(
                $this->package->repository_url,
                $this->package->id,
                $auth['token'],
                $auth['provider'],
                $auth['username'],
            );
            $repo->sync();

            // Per-type version import (Composer manifest, npm packument + tarball,
            // Python sdist) lives in one place, driven by the package type.
            app(GitSourceImporter::class)->import($this->package, $repo);

            $this->syncReadme($repo);

            $this->package->update([
                'sync_status' => SyncStatus::Synced,
                'sync_error' => null,
                'synced_at' => now(),
                'description' => $this->latestDescription() ?? $this->package->description,
            ]);

            PackageSynced::dispatch($this->package);
        } catch (Throwable $e) {
            // Make it visible in the DB AND rethrow, so the queue retries transient
            // errors (on success, Synced overwrites the Failed status).
            // The PackageSyncFailed event only fires in the failed() hook after the
            // final failure — otherwise a transient error would trigger webhook spam
            // on every retry.
            $this->markFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        // Only after exhausting all retries — a single sync.failed event.
        //
        // The exception that *ends* a job is not always the one that *describes* it. When
        // the queue gives up on its own — the retryUntil window closed, or attempts ran out
        // — what arrives here is MaxAttemptsExceededException, whose message ("has been
        // attempted too many times or run too long") tells an operator nothing about the
        // repository. handle() has already written the real reason to `sync_error` on every
        // attempt that got far enough to have one, so prefer that. Read straight out of the
        // column rather than trusting this deserialized copy — and as a value, not a model,
        // so a package deleted in the meantime is simply a null rather than an exception
        // thrown from inside the failure handler.
        $stored = $e instanceof MaxAttemptsExceededException
            ? Package::query()->whereKey($this->package->getKey())->value('sync_error')
            : null;

        $reason = is_string($stored) && $stored !== '' ? $stored : $e->getMessage();

        PackageSyncFailed::dispatch($this->package, $reason);
    }

    /**
     * A README is a nice-to-have on a detail page. It must never be able to fail a sync,
     * so every failure here is logged and swallowed rather than allowed to propagate.
     *
     * The column is written on every sync that reached a verdict, and only then. Two
     * outcomes, kept apart because they pull in opposite directions:
     *
     * - **The repository answered.** Whatever it says is now the truth: a README becomes
     *   the stored HTML, and no README — or one emptied down to nothing — clears the
     *   column. `readme_html` had no other writer at all (no admin edit path, not
     *   request-fillable, no reset in `packages:resync`), so without this a README deleted
     *   upstream, including one deleted *because* it leaked something, would be served
     *   from this column forever, and re-syncing — the one action an operator would reach
     *   for — would not undo it.
     * - **The repository did not answer,** or its README did not render. Nothing is known,
     *   so nothing is written and the previous value stands. A transient git failure or an
     *   unparsable README must never blank a working README page.
     *
     * `ReadmeLocator::find()` is what separates the two: it returns `null` only for a root
     * that listed cleanly and holds no README candidate, and throws when the listing or
     * the read failed. `ReadmeRenderer::render()` throws on a parse failure (deliberately —
     * see its docblock), which is the second failure shape and is caught separately, after
     * the locator has already established that a README does exist.
     *
     * Read at the mirror's HEAD (the default branch), not the newest version tag: unlike
     * `latestDescription()`, there is no single "current version" ref already resolved
     * here — GitSourceImporter imports every tag rather than picking one. Many
     * repositories (including one synced before its first tagged release) have no tags at
     * all, so a tag-based ref would leave the README empty for them. HEAD is also what a
     * reader lands on when opening the repository directly, matching what GitHub/GitLab
     * show as the project's README.
     */
    private function syncReadme(GitRepository $repo): void
    {
        $readmeRef = 'HEAD';

        try {
            $found = ReadmeLocator::find($repo, $readmeRef);
        } catch (Throwable $e) {
            $this->logReadmeFailure('readme lookup failed', $e);

            return; // Nothing learned about the repository — keep what is stored.
        }

        if ($found === null) {
            // The root listed cleanly and holds no README. That is an answer, not a
            // failure, so a previously stored one stops being served.
            $this->package->update(['readme_html' => null]);

            return;
        }

        try {
            $html = ReadmeRenderer::render($found['source'], $found['filename']);
        } catch (Throwable $e) {
            $this->logReadmeFailure('readme render failed', $e);

            return; // Keep the previous value rather than blanking on a parse error.
        }

        // An empty render means the file exists but says nothing — an answer too.
        $this->package->update(['readme_html' => $html !== '' ? $html : null]);
    }

    private function logReadmeFailure(string $message, Throwable $e): void
    {
        Log::warning($message, [
            'package_id' => $this->package->id,
            'reason' => $e->getMessage(),
        ]);
    }

    private function markFailed(string $message): void
    {
        $this->package->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $message,
        ]);
    }

    /** Description of the highest semver version (not sorted by sync time). */
    private function latestDescription(): ?string
    {
        $versions = $this->package->versions()->get();

        if ($versions->isEmpty()) {
            return null;
        }

        /** @var list<string> $sorted */
        $sorted = Semver::rsort($versions->pluck('version')->all());
        $latest = $versions->firstWhere('version', $sorted[0]);

        return $latest !== null ? ($latest->metadata['description'] ?? null) : null;
    }
}
