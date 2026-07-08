<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Models\Package;
use App\Services\Vcs\GitRepository;
use Composer\Semver\VersionParser;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;
use UnexpectedValueException;

class SyncPackage implements ShouldQueue
{
    use Queueable;

    public function __construct(public Package $package) {}

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->package->id))->releaseAfter(30)->expireAfter(300)];
    }

    public function handle(): void
    {
        if ($this->package->repository_url === null) {
            $this->package->update([
                'sync_status' => SyncStatus::Failed,
                'sync_error' => 'Package has no repository_url configured.',
            ]);

            return;
        }

        $this->package->update(['sync_status' => SyncStatus::Syncing]);

        try {
            $repo = new GitRepository($this->package->repository_url, $this->package->id);
            $repo->sync();
            $parser = new VersionParser;

            foreach ($repo->tags() as $tag) {
                try {
                    $normalized = $parser->normalize($tag);
                } catch (UnexpectedValueException) {
                    continue; // kein Versions-Tag
                }

                $composerJson = json_decode($repo->fileAtRef($tag, 'composer.json'), true);
                if (! is_array($composerJson)) {
                    continue;
                }

                $this->package->versions()->updateOrCreate(
                    ['version' => $normalized],
                    [
                        'version_pretty' => $tag,
                        'source_reference' => $repo->commitFor($tag),
                        'metadata' => $composerJson,
                        'released_at' => now(),
                    ],
                );
            }

            $latest = $this->package->versions()->first();
            $description = $latest !== null ? ($latest->metadata['description'] ?? null) : null;
            $description ??= $this->package->description;

            $this->package->update([
                'sync_status' => SyncStatus::Synced,
                'sync_error' => null,
                'synced_at' => now(),
                'description' => $description,
            ]);
        } catch (Throwable $e) {
            $this->package->update([
                'sync_status' => SyncStatus::Failed,
                'sync_error' => $e->getMessage(),
            ]);
        }
    }
}
