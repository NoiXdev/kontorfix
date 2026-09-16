<?php

namespace App\Services\Scanner;

use App\Enums\ScanStatus;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciScanReport;
use Illuminate\Support\Collection;

/**
 * One manifest's verdict, in the shape both the operator console and the customer portal
 * render — stated once so the two surfaces cannot come to disagree about the same image.
 *
 * Batched over a whole repository rather than asked per tag. Two tags pointing at one image
 * share one report by construction, so a per-tag reader would be doing the same work twice
 * for the commonest possible case (`latest` beside the version it currently means) and N
 * times for a repository with N tags, on a page that already counts its queries carefully.
 */
final class ScanCardPresenter
{
    public function __construct(
        // The one and only place `blocked` is allowed to come from — see present()'s own
        // comment on why this is injected rather than instantiated inline per call.
        private readonly ScanBlockGuard $guard,
    ) {}

    /**
     * @param  Collection<int, OciManifest>  $manifests
     * @param  Group|null  $group  when given, the card also says whether THIS registry
     *                             currently refuses the artifact and from when
     * @return array<string, array<string, mixed>> keyed by manifest id
     */
    public function forManifests(Collection $manifests, ?Group $group = null): array
    {
        if ($manifests->isEmpty() || ! config('kontorfix.scanner.enabled', false)) {
            return [];
        }

        $reports = OciScanReport::with('findings')
            ->whereIn('manifest_id', $manifests->pluck('id'))
            // Newest verdict wins when an instance has been switched from one scanner to
            // another and both left a report behind. A `pending`/`failed` report has
            // `scanned_at = null`, and Postgres sorts NULLs FIRST under a plain
            // `ORDER BY … DESC` — so a naive `orderByDesc('scanned_at')` would let a report
            // with no verdict at all outrank a genuine OK one from a scanner the instance
            // was switched away from. `NULLS LAST` keeps a row with no successful scan from
            // ever outranking one that has a verdict, regardless of how old that verdict is.
            ->orderByRaw('scanned_at DESC NULLS LAST')
            ->get()
            ->groupBy('manifest_id');

        $card = [];

        foreach ($manifests as $manifest) {
            $report = $reports->get($manifest->id)?->first();

            if ($report !== null) {
                $card[$manifest->id] = $this->present($report, $group);
            }
        }

        return $card;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OciScanReport $report, ?Group $group): array
    {
        // ScanBlockGuard::refuses() deliberately does not check the report's own status —
        // it would have to lazy-load the report per finding on a customer-facing page. That
        // obligation belongs to the caller, and this is the one place a finding reaches it:
        // a finding is only ever real when the report that carries it actually completed. A
        // Pending or Failed report has no findings by construction (ScanReportWriter never
        // writes one for either), but filtering on the report's own status here — rather
        // than trusting that invariant to hold everywhere forever — is what makes that true
        // by construction of THIS method too, and it is what keeps `refuses()` below from
        // ever being asked about a finding whose report never reached Ok.
        //
        // Worst severity first, earliest `first_seen_at` breaking a tie — the exact
        // ordering `ScanBlockGuard::blockingFinding()`'s SQL and `preview()` both use, so
        // the finding this card NAMES (see `$blocking` below) is the same one either of
        // those would name for the identical data.
        $findings = $report->status === ScanStatus::Ok
            ? $report->findings->sortBy([['severity_rank', 'desc'], ['first_seen_at', 'asc']])->values()
            : collect();

        $threshold = $group?->scan_block_severity;

        // The finding this card TALKS ABOUT when nothing blocks yet — the worst one that
        // would ever qualify under this registry's threshold, grace ignored. `atLeast()` is
        // `VulnerabilitySeverity`'s own comparison, the same one `refuses()` itself uses
        // internally — selecting which finding to NAME is not the blocking decision, so it
        // does not have to go through the guard. The decision itself — whether the artifact
        // is refused TODAY — never comes from this selection; see `blocked` below.
        $blocking = $threshold === null
            ? null
            : $findings->first(fn (OciScanFinding $f): bool => $f->severity->atLeast($threshold));

        return [
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            'scanner' => trim($report->scanner_name.' '.(string) $report->scanner_version),
            'scanned_at' => $report->scanned_at?->toDateTimeString(),
            // A verdict that is trustworthy but out of date is its own state. Presenting it
            // as fresh is how an operator stops noticing a scanner that died weeks ago.
            'stale' => $report->isStale(),
            'error' => $report->error,
            'counts' => [
                'critical' => $report->critical_count,
                'high' => $report->high_count,
                'medium' => $report->medium_count,
                'low' => $report->low_count,
                'unknown' => $report->unknown_count,
            ],
            // THE rule, asked through ScanBlockGuard::refuses() and nowhere else — never a
            // manual severity or grace-date comparison here, so this can never drift from
            // what a `docker pull` of this artifact actually decides. Checked over EVERY
            // finding, not just `$blocking`: severity and grace are independent axes, and
            // the worst-SEVERITY finding is not necessarily the one whose grace period
            // expired first, so only checking that one could miss a lower-severity finding
            // that is already, in fact, refusing the pull.
            'blocked' => $group !== null && $findings->contains(fn (OciScanFinding $f): bool => $this->guard->refuses($f, $group)),
            // The half that makes the grace period legible: when the named finding starts
            // (or started) blocking. `blocksAt()` — never date math against `now()` here —
            // is the one shared method `refuses()` and `preview()` both compute this from.
            'blocks_at' => $blocking !== null && $group !== null ? $blocking->blocksAt($group->scan_block_grace_days)->toDateString() : null,
            'findings' => $findings->map(fn (OciScanFinding $f): array => [
                'vulnerability_id' => $f->vulnerability_id,
                'severity' => $f->severity->value,
                'severity_label' => $f->severity->label(),
                'package_name' => $f->package_name,
                'installed_version' => $f->installed_version,
                // Null, never an empty string: the template says "kein Fix verfügbar"
                // rather than rendering a blank cell that reads as a missing value.
                'fixed_version' => $f->fixed_version,
                'first_seen_at' => $f->first_seen_at->toDateString(),
            ])->all(),
        ];
    }
}
