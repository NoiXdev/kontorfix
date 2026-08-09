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
     * so every failure mode here is swallowed and logged rather than allowed to propagate:
     *
     * - `ReadmeLocator::find()` never throws — a missing README, a root entry that turns
     *   out to be a directory, and a symlinked README all come back as `null` from inside
     *   the locator. `null` here just means "nothing to store"; the try/catch below is not
     *   what handles that case.
     * - `ReadmeRenderer::render()` *does* throw on a markdown parse failure (deliberately —
     *   see its docblock), which is the one realistic way this method can fail. That's
     *   what the catch is actually for: log which package failed to render and move on.
     * - A genuinely empty README (render() returning '') is treated the same as "nothing to
     *   store": no update.
     *
     * Either way — not found, empty, or unparsable — readme_html keeps whatever was stored
     * on the previous sync rather than being blanked, so a transient rendering failure
     * never makes an already-working README page regress.
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

            if ($found !== null) {
                $html = ReadmeRenderer::render($found['source'], $found['filename']);

                if ($html !== '') {
                    $this->package->update(['readme_html' => $html]);
                }
            }
        } catch (Throwable $e) {
            Log::warning('readme extraction failed', [
                'package_id' => $this->package->id,
                'reason' => $e->getMessage(),
            ]);
        }
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
