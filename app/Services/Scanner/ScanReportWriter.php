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

        return $row;
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
