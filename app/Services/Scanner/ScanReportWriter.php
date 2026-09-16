<?php

namespace App\Services\Scanner;

use App\Enums\ScanStatus;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY writer of `oci_scan_reports` and `oci_scan_findings`, and therefore the only
 * place `first_seen_at` is decided.
 *
 * That single-writer rule is not tidiness. `first_seen_at` is the whole mechanism of the
 * grace period, and its correct value is invisible from any individual call site: "now" is
 * right for a finding nobody has seen before and catastrophically wrong for the same
 * finding on tonight's rescan, where it silently restarts a clock that is supposed to be
 * running out. One writer, one decision, one test.
 */
final class ScanReportWriter
{
    /**
     * Reserved placeholder scanner identity for a report row written when a scan failed
     * before any scanner could say who it is — there is no real name to key the row on yet.
     *
     * No real scanner is ever given this name: `recordSuccess()` and `recordFailure()` both
     * take the actual adapter name from `ScannerMetadata`/the caller, and a row under this
     * placeholder is deleted the moment a real name is known for the same manifest (see
     * `deletePlaceholder()`), so it can never linger beside — or shadow — a genuine verdict.
     */
    public const UNIDENTIFIED_SCANNER = 'Scanner (unbekannt)';

    /** The row for a manifest that has been queued but never scanned. */
    public function recordPending(OciManifest $manifest, string $scannerName): OciScanReport
    {
        return OciScanReport::firstOrCreate(
            ['manifest_id' => $manifest->id, 'scanner_name' => $scannerName],
            ['status' => ScanStatus::Pending],
        );
    }

    /**
     * A completed scan. Replaces the finding set with what the scanner just reported, and
     * carries `first_seen_at` forward for every pairing that was already there.
     */
    public function recordSuccess(OciManifest $manifest, ScannerMetadata $scanner, AdapterScanReport $report): OciScanReport
    {
        return DB::transaction(function () use ($manifest, $scanner, $report): OciScanReport {
            $row = OciScanReport::firstOrNew([
                'manifest_id' => $manifest->id,
                'scanner_name' => $scanner->name,
            ]);

            $row->fill([
                'scanner_version' => $scanner->version,
                'status' => ScanStatus::Ok,
                'scanned_at' => now(),
                // A success clears the failure marks: the verdict is current again, so
                // nothing should keep rendering it as stale.
                'error' => null,
                'failed_at' => null,
                ...$this->counts($report),
            ])->save();

            $this->replaceFindings($row, $report);

            // The scanner has now identified itself for real: any placeholder row left
            // behind by an earlier "unreachable before it could say who it is" failure is
            // superseded by this one and must not survive beside it.
            if ($scanner->name !== self::UNIDENTIFIED_SCANNER) {
                $this->deletePlaceholder($manifest);
            }

            return $row;
        });
    }

    /**
     * A failed attempt.
     *
     * Records the error and when it happened — and DOES NOT touch `status`, `scanned_at`,
     * the counts or the findings when a verdict already exists. A failed rescan that
     * discarded the last good answer would mean that stopping the scanner unblocks every
     * artifact at once; the failure is recorded beside the verdict instead, where the health
     * check and the UI both show it as stale.
     */
    public function recordFailure(OciManifest $manifest, string $scannerName, string $error): OciScanReport
    {
        return DB::transaction(function () use ($manifest, $scannerName, $error): OciScanReport {
            $row = OciScanReport::firstOrNew([
                'manifest_id' => $manifest->id,
                'scanner_name' => $scannerName,
            ]);

            $row->error = $error;
            $row->failed_at = now();

            // Only a manifest that has NEVER been scanned successfully lands in Failed.
            if ($row->status !== ScanStatus::Ok) {
                $row->status = ScanStatus::Failed;
            }

            $row->save();

            // A real name recovered — any placeholder row from an earlier "couldn't even
            // identify itself" failure on this manifest is now stale and must go. Skipped
            // when $scannerName IS the placeholder: that would mean deleting the very row
            // just written above.
            if ($scannerName !== self::UNIDENTIFIED_SCANNER) {
                $this->deletePlaceholder($manifest);
            }

            return $row;
        });
    }

    /**
     * Removes this manifest's placeholder row, if any. Real scanner rows are never touched:
     * the query is scoped to the reserved placeholder name only, and every row it finds is
     * deleted through the model so the usual Eloquent lifecycle still applies.
     */
    private function deletePlaceholder(OciManifest $manifest): void
    {
        OciScanReport::query()
            ->where('manifest_id', $manifest->id)
            ->where('scanner_name', self::UNIDENTIFIED_SCANNER)
            ->get()
            ->each(fn (OciScanReport $placeholder) => $placeholder->delete());
    }

    /**
     * @return array<string, int>
     */
    private function counts(AdapterScanReport $report): array
    {
        $counts = [
            'critical_count' => 0,
            'high_count' => 0,
            'medium_count' => 0,
            'low_count' => 0,
            'unknown_count' => 0,
        ];

        foreach ($report->findings as $finding) {
            $counts[$finding->severity->value.'_count']++;
        }

        return $counts;
    }

    private function replaceFindings(OciScanReport $row, AdapterScanReport $report): void
    {
        // Keyed exactly as the unique index is — one CVE legitimately appears against
        // several packages, and collapsing on the CVE alone would drop findings.
        $existing = $row->findings()->get()->keyBy(
            fn (OciScanFinding $f): string => $f->vulnerability_id.'|'.$f->package_name
        );

        $seen = [];

        foreach ($report->findings as $finding) {
            $key = $finding->id.'|'.$finding->packageName;
            $seen[] = $key;
            $previous = $existing->get($key);

            $row->findings()->updateOrCreate(
                ['vulnerability_id' => $finding->id, 'package_name' => $finding->packageName],
                [
                    'severity' => $finding->severity,
                    // Not set here — OciScanFinding::booted() derives severity_rank from
                    // severity on every save, so this writer never states it twice.
                    'installed_version' => $finding->installedVersion,
                    'fixed_version' => $finding->fixedVersion,
                    // THE LINE THE GRACE PERIOD RESTS ON. Carried forward when this pairing
                    // was already known; stamped only for one nobody has seen before.
                    'first_seen_at' => $previous !== null ? $previous->first_seen_at : now(),
                ],
            );
        }

        // Withdrawn, fixed, or no longer applicable: gone from the report, gone from here.
        // A finding that reappears later is genuinely new and starts its grace afresh, which
        // is right — the artifact was clean in between.
        $row->findings()
            ->get()
            ->reject(fn (OciScanFinding $f): bool => in_array($f->vulnerability_id.'|'.$f->package_name, $seen, true))
            ->each(fn (OciScanFinding $f) => $f->delete());
    }
}
