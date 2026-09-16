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

        // Same shape as `oci:sweep`'s own guard, and for the same reason: `--limit=-1`
        // would otherwise reach Builder::limit(), which silently ignores a negative value
        // and enqueues the ENTIRE candidate set in one tick — exactly what a budget exists
        // to prevent. `--limit=abc` casts to 0, which queues nothing and reports the whole
        // registry as "left behind" — equally wrong in the other direction. Refused with a
        // non-zero exit rather than clamped, so a typo'd cron entry is loud instead of
        // quietly running under a different budget than the operator intended.
        $limit = $this->option('limit') !== null
            ? (int) $this->option('limit')
            : (int) config('kontorfix.scanner.rescan_limit', 200);

        if ($limit < 1) {
            $this->error('Das Budget muss mindestens 1 Manifest betragen.');

            return self::FAILURE;
        }

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
        //
        // MAX rather than MIN, deliberately: MIN would let a manifest carrying one ancient
        // row from a retired, long-swapped-out scanner sort at the head of every nightly run
        // forever, however recently the CURRENT scanner verified it — the stale row would
        // starve everything else of the budget indefinitely. MAX's failure mode is the
        // opposite and bounded: right after a scanner swap such a manifest is deprioritised
        // (it now looks "recently scanned" via the retired scanner's row) until that row
        // ages past its peers, a one-time, self-correcting cost rather than a permanent one.
        //
        // `failed_at` is aggregated alongside `scanned_at` because the ORDER below is "least
        // recently ATTEMPTED first", not "least recently SUCCEEDED first". A manifest whose
        // every scan fails — the repository detached from every registry records
        // "keiner Registry zugeordnet" on every run, and needs no exotic setup to reach —
        // never receives a `scanned_at` at all. Ordering on that column alone left it sorting
        // as never-scanned on EVERY run, deterministically the same rows at the head of the
        // queue: with as many such manifests as the budget allows, no healthy image is ever
        // rescanned again, while `scanner-freshness` stays green off the back of fresh pushes.
        $latestScans = OciScanReport::query()
            ->selectRaw('manifest_id, MAX(scanned_at) as latest_scanned_at, MAX(failed_at) as latest_failed_at')
            ->groupBy('manifest_id');

        // A failed attempt is still an attempt. Deprioritising after one is what keeps the
        // rotation moving past an artifact that can never produce a verdict. GREATEST, not
        // COALESCE: COALESCE would freeze the key at the last SUCCESS forever, once there is
        // one — a manifest that succeeded once and has failed every rescan since would keep
        // that old `scanned_at` as its key permanently, sitting at the head of every nightly
        // run while healthy manifests advance past it. Postgres GREATEST ignores NULLs, so the
        // result is NULL only when both inputs are, which preserves the "never attempted sorts
        // first" clause below.
        $lastAttempt = 'greatest(latest_scans.latest_scanned_at, latest_scans.latest_failed_at)';

        $candidates = OciManifest::query()
            ->whereHas('package', fn ($q) => $q->where('type', PackageType::Docker))
            // Never-attempted first, then the stalest attempt. `leftJoinSub` rather than
            // `whereDoesntHave`, because the ORDER needs the report's timestamp and a
            // manifest with no report must sort first rather than be excluded.
            ->leftJoinSub($latestScans, 'latest_scans', 'latest_scans.manifest_id', '=', 'oci_manifests.id')
            ->orderByRaw("{$lastAttempt} is null desc")
            ->orderByRaw($lastAttempt)
            ->orderBy('oci_manifests.id')
            ->select('oci_manifests.id');

        $queued = (clone $candidates)->limit($limit)->pluck('oci_manifests.id');

        foreach ($queued as $id) {
            ScanOciArtifact::dispatch((string) $id);
        }

        // What the budget left behind, counted over the manifests that actually NEED a scan
        // rather than over every Docker manifest on the instance. The plain total fired every
        // night on any registry larger than the budget — including one where every manifest
        // had been verified hours earlier — which is how a warning stops being read. This
        // line is the only signal that the rotation is not keeping up, so it has to mean it.
        $staleBefore = now()->subDays((int) config('kontorfix.scanner.freshness_days', 7));

        $remaining = (clone $candidates)
            ->whereNotIn('oci_manifests.id', $queued)
            ->where(fn ($q) => $q
                ->whereRaw("{$lastAttempt} is null")
                ->orWhereRaw("{$lastAttempt} <= ?", [$staleBefore]))
            ->count();

        $this->info("{$queued->count()} Manifest(e) zur Prüfung eingereiht.");

        if ($remaining > 0) {
            $this->warn("{$remaining} Manifest(e) mit veraltetem Befund bleiben für den nächsten Lauf übrig — das Budget für diesen Lauf ist erreicht.");
        }

        return self::SUCCESS;
    }
}
