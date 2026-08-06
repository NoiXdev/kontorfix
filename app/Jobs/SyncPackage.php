<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;
use UnexpectedValueException;

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
            $repo = new GitRepository($this->package->repository_url, $this->package->id, $this->package->repository_token);
            $repo->sync();
            $parser = new VersionParser;

            foreach ($repo->tags() as $tag) {
                try {
                    $normalized = $parser->normalize($tag);
                } catch (UnexpectedValueException) {
                    continue; // not a version tag
                }

                try {
                    $composerJson = json_decode($repo->fileAtRef($tag, 'composer.json'), true);
                } catch (Throwable) {
                    continue; // Tag without composer.json — skip it, don't abort the whole sync
                }

                if (! is_array($composerJson)) {
                    continue;
                }

                $this->package->versions()->updateOrCreate(
                    ['version' => $normalized],
                    [
                        'version_pretty' => $tag,
                        'source_reference' => $repo->commitFor($tag),
                        'metadata' => $composerJson,
                        'released_at' => $repo->committedAt($tag), // stable commit date, not now()
                    ],
                );
            }

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
