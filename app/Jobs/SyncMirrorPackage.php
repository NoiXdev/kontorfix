<?php

namespace App\Jobs;

use App\Enums\SyncStatus;
use App\Events\PackageSynced;
use App\Events\PackageSyncFailed;
use App\Models\Package;
use App\Services\Mirror\MirrorImporter;
use Composer\Semver\Semver;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * The mirror counterpart to SyncPackage: syncs a mirror-sourced package
 * (Package::isMirrorSourced()) against its assigned MirrorSource instead of cloning a git
 * repository. Every timing property below is copied verbatim from SyncPackage rather than
 * re-derived, because both jobs share the same Horizon supervisor (config/horizon.php reads
 * SyncPackage::TIMEOUT for its 'timeout' key) and the same queue connection's `retry_after` —
 * a second, independently-chosen timeout here would only be a second place for that relation
 * to drift out of. See SyncPackage::$timeout, ::$maxExceptions, ::backoff() and
 * ::retryUntil() for the full reasoning; nothing about it is mirror-specific.
 */
class SyncMirrorPackage implements ShouldQueue
{
    use Queueable;

    /**
     * Seconds this job may run before the worker kills it — identical to SyncPackage's, and
     * asserted equal to it in a test, so the Horizon supervisor contract that reads
     * SyncPackage::TIMEOUT for its 'timeout' key covers this job too without a second entry.
     */
    public const TIMEOUT = SyncPackage::TIMEOUT;

    /** Seconds a job parked behind the per-package overlap lock waits before trying again. */
    private const RELEASE_AFTER = 30;

    public int $timeout = self::TIMEOUT;

    /**
     * See SyncPackage::$maxExceptions: there is deliberately no `$tries` here either,
     * `retryUntil()` below governs the retry window, and this counts thrown exceptions only
     * — a release by WithoutOverlapping (contention, nobody's fault) is free.
     */
    public int $maxExceptions = 3;

    public function __construct(public Package $package) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /** See SyncPackage::retryUntil() — the same derivation, the same window. */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds($this->maxExceptions * $this->timeout + array_sum($this->backoff()));
    }

    /**
     * See SyncPackage::middleware() — the per-package overlap lock, keyed the same way
     * (package id), so a git sync and a mirror sync of the same package can never run
     * concurrently either.
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
        // Not every dispatch site checks isMirrorSourced() before queuing this job (the
        // packages:resync command dispatches per row after inspecting source_mode itself,
        // but a row could in principle be re-saved into another mode between dispatch and
        // execution) — a configuration error, not a transient one, so it is failed the same
        // way rather than left to reach the importer with the wrong source entirely.
        if (! $this->package->isMirrorSourced()) {
            $this->markFailed(sprintf(
                'Paket ist nicht mirror-basiert (Quellmodus „%s“) — ein Mirror-Sync ist hierfür nicht vorgesehen.',
                $this->package->source_mode->label(),
            ));

            return; // Configuration error — retrying makes no sense
        }

        $source = $this->package->mirrorSource;
        if ($source === null) {
            // mirror_source_id is nullOnDelete: the Mirror-Quelle this package pointed at
            // was deleted out from under it. Retrying cannot bring it back — an operator has
            // to assign a new one.
            $this->markFailed('Für dieses Paket ist keine Mirror-Quelle mehr hinterlegt — im Tab „Quelle“ eine neue Mirror-Quelle zuweisen, dann erneut synchronisieren.');

            return; // Configuration error — retrying makes no sense
        }

        // A MirrorSource is scoped to exactly one organization (no cross-organization
        // sharing the way GitCredential has — see MirrorSource's doc comment), so a package
        // pointing at one belonging to another organization can only be the result of the
        // package having moved organizations after the assignment was made. Checked before
        // the importer ever runs, the same way SyncPackage checks credential usability
        // before reaching the repository.
        if ($source->organization_id !== $this->package->organization_id) {
            $this->markFailed('Die zugewiesene Mirror-Quelle gehört zu einer anderen Organisation — im Tab „Quelle“ eine gültige Mirror-Quelle zuweisen.');

            return; // Configuration error — retrying makes no sense
        }

        // MirrorImporter::import() dispatches on $package->type alone and would otherwise
        // read a Composer feed as if it were npm's (or vice versa) — a mismatch here is a
        // configuration error the importer itself has no way to detect from inside.
        if ($source->type !== $this->package->type) {
            $this->markFailed(sprintf(
                'Die zugewiesene Mirror-Quelle ist für %s, dieses Paket ist aber vom Typ %s — im Tab „Quelle“ eine passende Mirror-Quelle zuweisen.',
                $source->type->label(),
                $this->package->type->label(),
            ));

            return; // Configuration error — retrying makes no sense
        }

        $this->package->update(['sync_status' => SyncStatus::Syncing]);

        try {
            // Per-type version import (Composer p2 feed, npm packument + tarball, PyPI
            // simple API + sdist/wheel) lives in one place, driven by the package type —
            // README handling included, where the upstream feed carries one (npm).
            app(MirrorImporter::class)->import($this->package, $source);

            $this->package->update([
                'sync_status' => SyncStatus::Synced,
                'sync_error' => null,
                'synced_at' => now(),
                'description' => $this->latestDescription() ?? $this->package->description,
            ]);

            // Same pattern as GitCredential::isUsableBy()'s caller in Package::gitAuth():
            // forceFill + saveQuietly() stamps provenance without re-triggering model
            // events (activity log, etc.) for what is bookkeeping, not a domain change.
            $source->forceFill(['last_used_at' => now()])->saveQuietly();

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
        // See SyncPackage::failed() for the full reasoning — identical here: prefer the
        // reason already written to sync_error over MaxAttemptsExceededException's opaque
        // message, and a package deleted in the meantime never reaches this method at all
        // (CallQueuedHandler::failed() throws ModelNotFoundException while restoring it).
        $stored = $e instanceof MaxAttemptsExceededException
            ? Package::query()->whereKey($this->package->getKey())->value('sync_error')
            : null;

        $reason = is_string($stored) && $stored !== '' ? $stored : $e->getMessage();

        PackageSyncFailed::dispatch($this->package, $reason);
    }

    private function markFailed(string $message): void
    {
        $this->package->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $message,
        ]);
    }

    /** Description of the highest semver version (not sorted by sync time). Same as SyncPackage::latestDescription(). */
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
