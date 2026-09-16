<?php

namespace App\Jobs;

use App\Models\OciManifest;
use App\Services\Scanner\ScanRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scan one manifest.
 *
 * Unique on the MANIFEST, not on the tag: `latest` and `1.4.0` pointing at one image are
 * one scan, and a multi-tag build push would otherwise queue the same work once per tag.
 *
 * The manifest is carried by ID rather than as a serialized model so a manifest deleted
 * between dispatch and execution — an ordinary outcome of retention running overnight — is
 * a quiet no-op instead of a ModelNotFoundException per retry.
 */
class ScanOciArtifact implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Declared, never inherited: config/horizon.php raises the supervisor timeout to
     * SyncPackage's 900s and Worker::timeoutForJob() hands that to any job that declares
     * none. Sized to outlast the runner's own poll budget plus its final write, so the
     * timeout that fires is the one with a recorded explanation attached.
     */
    public int $timeout;

    /**
     * How long the uniqueness lock survives — deliberately longer than $timeout, and for
     * that reason NOT left at the ShouldBeUnique default of 0 (which never expires on its
     * own). Workers here are killed mid-job by design on every Portainer redeploy, and a
     * lock with no expiry would then outlive the job that took it: the manifest would never
     * be scanned again, not by a retry and not by the nightly rescan, until someone notices
     * and clears the lock by hand. Set in the constructor alongside $timeout because a
     * property initialiser cannot read config.
     */
    public int $uniqueFor;

    public function __construct(public readonly string $manifestId)
    {
        $pollBudget = (int) config('kontorfix.scanner.timeout', 600);
        $this->timeout = $pollBudget + 180;
        // Longer than $timeout by construction: the lock must outlive the worst case the
        // job itself is sized for, or it could expire while a legitimately slow scan is
        // still running and let a duplicate job start alongside it.
        $this->uniqueFor = $pollBudget + 300;
    }

    public function uniqueId(): string
    {
        return $this->manifestId;
    }

    public function handle(ScanRunner $runner): void
    {
        $manifest = OciManifest::with('package.organization')->find($this->manifestId);

        if ($manifest === null || ! ScanRunner::scannable($manifest)) {
            return;
        }

        $runner->run($manifest);
    }
}
