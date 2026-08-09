<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use App\Services\Vcs\GitSourceImporter;
use App\Services\Vcs\ReadmeLocator;
use App\Services\Vcs\ReadmeRenderer;
use Composer\Semver\Semver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncPackage implements ShouldQueue
{
    use Queueable;

    /** Transient errors (network, git timeout) are retried. */
    public int $tries = 3;

    public function __construct(public Package $package) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->package->id))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(): void
    {
        if ($this->package->repository_url === null) {
            $this->markFailed('Package has no repository_url configured.');

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
        PackageSyncFailed::dispatch($this->package, $e->getMessage());
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
