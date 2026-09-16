<?php

namespace App\Console\Commands;

use App\Enums\PackageType;
use App\Jobs\ScanOciArtifact;
use App\Models\OciManifest;
use App\Models\OciScanReport;
use Illuminate\Console\Command;

/**
 * The daily rescan — the point of the whole feature.
 *
 * An image does not change; its verdict does. A scan performed at push time is a statement
 * about the vulnerability database on that day, and it goes stale without anything about
 * the artifact moving. This is what re-asks the question.
 *
 * Budgeted per run like `oci:sweep`, and for the same reason: a registry with ten thousand
 * manifests must not enqueue ten thousand jobs in one scheduler tick. What the budget left
 * behind is printed, never silent — a bounded run that said nothing would read as
 * "everything is scanned", which is exactly the false assurance this feature exists to
 * remove.
 */
class RescanOciArtifacts extends Command
{
    protected $signature = 'oci:scan {--limit= : Manifests to queue this run (defaults to kontorfix.scanner.rescan_limit)}';

    protected $description = 'Queue vulnerability scans for OCI manifests, oldest verdict first';

    public function handle(): int
    {
        if (! config('kontorfix.scanner.enabled', false)) {
            $this->info('Die Schwachstellenprüfung ist deaktiviert (KONTORFIX_SCANNER_ENABLED).');

            return self::SUCCESS;
        }

        $limit = (int) ($this->option('limit') ?: config('kontorfix.scanner.rescan_limit', 200));

        // `oci_scan_reports` carries one row per (manifest, scanner) by design — an instance
        // that switched scanners leaves both scanners' rows behind on the same manifest. A
        // plain leftJoin against that table would therefore multiply the manifest, once per
        // report row: $total would be inflated and a manifest with two reports could spend
        // two slots of this run's budget on itself (ShouldBeUnique hides the duplicate
        // DISPATCH, but not the wasted budget). Joining against a per-manifest aggregate
        // instead — the most recent `scanned_at` across every scanner that has ever reported
        // on this manifest — keeps the join to exactly one row per manifest while preserving
        // the ordering: MAX() ignores NULLs, so a manifest whose only rows are unscanned
        // (never-succeeded) attempts still sorts as null, alongside one with no report row
        // at all.
        $latestScans = OciScanReport::query()
            ->selectRaw('manifest_id, MAX(scanned_at) as latest_scanned_at')
            ->groupBy('manifest_id');

        $candidates = OciManifest::query()
            ->whereHas('package', fn ($q) => $q->where('type', PackageType::Docker))
            // Never-scanned first, then the stalest verdict. `leftJoinSub` rather than
            // `whereDoesntHave`, because the ORDER needs the report's timestamp and a
            // manifest with no report must sort first rather than be excluded.
            ->leftJoinSub($latestScans, 'latest_scans', 'latest_scans.manifest_id', '=', 'oci_manifests.id')
            ->orderByRaw('latest_scans.latest_scanned_at is null desc')
            ->orderBy('latest_scans.latest_scanned_at')
            ->orderBy('oci_manifests.id')
            ->select('oci_manifests.id');

        $total = (clone $candidates)->count();
        $queued = $candidates->limit($limit)->pluck('oci_manifests.id');

        foreach ($queued as $id) {
            ScanOciArtifact::dispatch((string) $id);
        }

        $remaining = max(0, $total - $queued->count());

        $this->info("{$queued->count()} Manifest(e) zur Prüfung eingereiht.");

        if ($remaining > 0) {
            $this->warn("{$remaining} Manifest(e) bleiben für den nächsten Lauf übrig — das Budget für diesen Lauf ist erreicht.");
        }

        return self::SUCCESS;
    }
}
