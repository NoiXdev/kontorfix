<?php

namespace App\Services\Oci\Sweeper;

use App\Enums\PackageType;
use App\Models\OciBlob;
use App\Models\OciBlobUpload;
use App\Models\OciManifest;
use App\Models\Organization;
use App\Models\Package;
use App\Services\Oci\BlobStore;
use App\Services\Registry\OciSettings;
use App\Support\Oci\SweepReport;
use Illuminate\Database\Eloquent\Builder;

/**
 * The mechanical half of the cleanup design. It knows no retention rules — it walks the
 * reachability graph OciReachability builds and removes what is both unreachable and older
 * than the grace period, plus two leaks that are not blobs at all.
 *
 * Four jobs, each for its own reason:
 *
 *   1. Unreachable MANIFESTS. Not decoration: retention deletes tag rows, and
 *      ManifestStore::find() resolves a manifest by digest whether or not a tag points at
 *      it — so without this pass, a removed tag's image still answers
 *      `GET /v2/<repo>/manifests/sha256:…` while pass 2 collects its layers. The end state
 *      would be a manifest that resolves and whose layers 404: an image that pulls halfway.
 *   2. Unreachable BLOBS, per organization — the disk itself.
 *   3. Expired upload SESSIONS. Nothing references them, so pass 2 never sees them: they
 *      are not unreferenced blobs, they are not blobs at all. The condition is the
 *      session's own `expires_at`, never the grace period — the grace period protects a
 *      push in flight, `expires_at` is the point the registry stops honouring a resume,
 *      and tying the two together would mean lowering one silently shortens the other.
 *   4. Empty push-created REPOSITORIES — rows whose `auto_created_at` marks them as a
 *      push's own creation, that never received an image and whose sessions are all dead.
 *
 * Order between 1 and 2 is irrelevant to correctness, because blob reachability is rooted
 * at tags and never at the manifest table's contents. 1 runs first because it is cheap and
 * shrinks nothing pass 2 depends on — it is simply the sensible sequence to report in.
 *
 * Every delete here is IDEMPOTENT — a row already gone deletes nothing, a file already gone
 * is a no-op on both disk drivers — which is what makes the scheduled command and a
 * manually dispatched job safe to OVERLAP. They can overlap: `withoutOverlapping()` guards
 * the schedule against itself and ShouldBeUnique guards the job queue against itself, and
 * neither guards one against the other. Claiming mutual exclusion here would be claiming
 * something nothing provides; idempotence is the property that actually holds.
 */
class OciSweeper
{
    public function __construct(
        private OciReachability $reachability,
        private OciSettings $settings,
        private BlobStore $blobs,
    ) {}

    /**
     * One full sweep. $blobLimit bounds only the blob pass — manifests, sessions and
     * repositories are cheap row deletes, while a blob delete moves a file; a first run
     * against a never-swept registry must not spend an hour in the scheduler. Hitting the
     * bound is REPORTED (blobsRemaining), never silent.
     */
    public function sweep(int $blobLimit): SweepReport
    {
        $report = $this->run(dryRun: false, blobLimit: $blobLimit);

        if ($report->hasWork()) {
            // One summary per sweep that did something, nothing for a no-op: a nightly
            // "nothing happened" entry per day buries the entries that matter.
            activity('oci')
                ->event('storage_swept')
                ->withProperties($report->toArray())
                ->log(sprintf(
                    'Speicherbereinigung: %d Manifest(e), %d Blob(s) (%d Byte), %d Upload-Session(s), %d leere(s) Repository/Repositories entfernt',
                    $report->manifestsRemoved,
                    $report->blobsRemoved,
                    $report->bytesReclaimed,
                    $report->uploadsRemoved,
                    $report->repositoriesRemoved,
                ));
        }

        return $report;
    }

    /**
     * The same reads with every delete replaced by a counter — what the admin page renders
     * on every GET, so it MUST remove nothing. Deliberately not sweep(0): a limit of 0
     * stops the blob pass but not the other three, so pending() implemented that way would
     * delete manifests, sessions and repositories on page load.
     */
    public function pending(): SweepReport
    {
        return $this->run(dryRun: true, blobLimit: 0);
    }

    private function run(bool $dryRun, int $blobLimit): SweepReport
    {
        $cutoff = $this->settings->graceCutoff();

        $manifestsRemoved = 0;
        $blobsRemoved = 0;
        $bytesReclaimed = 0;
        $blobsHeldByGrace = 0;
        $blobsRemaining = 0;

        foreach (Organization::query()->lazyById() as $organization) {
            // Built ONCE per organization and shared by the manifest and blob passes —
            // never per row: the walk reads every manifest of the organization, and a
            // per-row rebuild would make the sweep quadratic.
            $reachable = $this->reachability->forOrganization((string) $organization->id);

            $packageIds = Package::query()->where('organization_id', $organization->id)->pluck('id');

            // Pass 1: unreachable manifests past the grace period. The age guard carries
            // the same weight as on blobs — buildx writes a multi-arch image's children by
            // digest and the tagged index LAST, so every child is unreachable in between.
            $manifests = OciManifest::query()
                ->whereIn('package_id', $packageIds)
                ->where('created_at', '<', $cutoff)
                ->lazyById();

            foreach ($manifests as $manifest) {
                if ($reachable->hasManifest($manifest->id)) {
                    continue;
                }

                $manifestsRemoved++;

                if (! $dryRun) {
                    $manifest->delete();
                }
            }

            // Pass 2: unreachable blobs. Grace first, budget second — a blob inside the
            // grace period is HELD (and counted as such, the number the admin page shows),
            // not queued against the budget.
            foreach (OciBlob::query()->where('organization_id', $organization->id)->lazyById() as $blob) {
                if ($reachable->hasBlob($blob->digest)) {
                    continue;
                }

                if ($blob->created_at !== null && $blob->created_at->greaterThanOrEqualTo($cutoff)) {
                    $blobsHeldByGrace++;

                    continue;
                }

                if ($dryRun || $blobsRemoved >= $blobLimit) {
                    $blobsRemaining++;

                    continue;
                }

                $this->blobs->deleteBlobStorage($blob);
                $blob->delete();

                $blobsRemoved++;
                $bytesReclaimed += (int) $blob->size;
            }
        }

        // Pass 3: expired upload sessions, instance-wide — they are package-scoped rows,
        // and nothing about them needs the reachability graph.
        $uploadsRemoved = 0;

        foreach (OciBlobUpload::query()->where('expires_at', '<', now())->lazyById() as $upload) {
            $uploadsRemoved++;

            if (! $dryRun) {
                $this->blobs->discardUpload($upload);
            }
        }

        // Pass 4: empty push-created repositories.
        $repositoriesRemoved = 0;

        $repositories = Package::query()
            ->where('type', PackageType::Docker)
            ->whereNotNull('auto_created_at')
            ->where('auto_created_at', '<', $cutoff)
            ->whereDoesntHave('ociManifests')
            ->whereDoesntHave('ociTags')
            // Not redundant with the age check above: begin() writes a fixed 24-hour
            // expires_at while the grace period is configurable down to one hour, so a
            // resumable session can outlive the cutoff — and deleting the package would
            // cascade the session away underneath a client still entitled to resume it.
            ->whereDoesntHave('ociBlobUploads', fn (Builder $query) => $query->where('expires_at', '>=', now()))
            ->lazyById();

        foreach ($repositories as $package) {
            $repositoriesRemoved++;

            if (! $dryRun) {
                // A hard delete, recorded by the model's own LogsActivity — this pass
                // needs no audit code of its own.
                $package->delete();
            }
        }

        return new SweepReport(
            manifestsRemoved: $manifestsRemoved,
            blobsRemoved: $blobsRemoved,
            bytesReclaimed: $bytesReclaimed,
            blobsHeldByGrace: $blobsHeldByGrace,
            blobsRemaining: $blobsRemaining,
            uploadsRemoved: $uploadsRemoved,
            repositoriesRemoved: $repositoriesRemoved,
        );
    }
}
