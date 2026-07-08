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

    /** Transiente Fehler (Netzwerk, git timeout) werden erneut versucht. */
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

            return; // Konfigurationsfehler — kein Retry sinnvoll
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

                try {
                    $composerJson = json_decode($repo->fileAtRef($tag, 'composer.json'), true);
                } catch (Throwable) {
                    continue; // Tag ohne composer.json — überspringen, nicht den ganzen Sync abbrechen
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
                        'released_at' => $repo->committedAt($tag), // stabiles Commit-Datum, kein now()
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
            // In der DB sichtbar machen UND weiterwerfen, damit die Queue transiente
            // Fehler erneut versucht (bei Erfolg überschreibt Synced den Failed-Status).
            $this->markFailed($e->getMessage());

            PackageSyncFailed::dispatch($this->package, $e->getMessage());

            throw $e;
        }
    }

    private function markFailed(string $message): void
    {
        $this->package->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $message,
        ]);
    }

    /** Description der höchsten Semver-Version (nicht nach Sync-Zeit sortiert). */
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
