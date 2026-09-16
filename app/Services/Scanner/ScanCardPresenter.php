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
    /**
     * Never shown to a customer — the portal's own generic replacement for `error`. Never a
     * substring of any real scanner error: `ScanRunner::run()`'s failures embed the internal
     * scanner URL, the `KONTORFIX_SCANNER_URL`/`KONTORFIX_SCANNER_ALLOWED_HOSTS` env var
     * names, an operator instruction, and raw cURL text — none of which is this customer's
     * business merely because their image happens to be stale.
     */
    private const CUSTOMER_FACING_ERROR = 'Die letzte Prüfung ist fehlgeschlagen.';

    public function __construct(
        // The one and only place `blocked` is allowed to come from — see present()'s own
        // comment on why this is injected rather than instantiated inline per call.
        private readonly ScanBlockGuard $guard,
    ) {}

    /**
     * @param  Collection<int, OciManifest>  $manifests
     * @param  Group|null  $group  when given, the card also says whether THIS registry
     *                             currently refuses the artifact and from when
     * @param  bool  $forCustomer  strips operator-internal detail (the scanner's product
     *                             name/version, and the raw failure text) for the portal —
     *                             see CUSTOMER_FACING_ERROR's own doc. The STALE fact itself
     *                             is never withheld, only the string that explains it.
     * @return array<string, array<string, mixed>> keyed by manifest id
     */
    public function forManifests(Collection $manifests, ?Group $group = null, bool $forCustomer = false): array
    {
        if ($manifests->isEmpty() || ! config('kontorfix.scanner.enabled', false)) {
            return [];
        }

        $reportsByManifest = OciScanReport::with('findings')
            ->whereIn('manifest_id', $manifests->pluck('id'))
            // Newest verdict wins for STATUS, COUNTS and the scanner name when an instance
            // has been switched from one scanner to another and both left a report behind.
            // A `pending`/`failed` report has `scanned_at = null`, and Postgres sorts NULLs
            // FIRST under a plain `ORDER BY … DESC` — so a naive `orderByDesc('scanned_at')`
            // would let a report with no verdict at all outrank a genuine OK one from a
            // scanner the instance was switched away from. `NULLS LAST` keeps a row with no
            // successful scan from ever outranking one that has a verdict, regardless of how
            // old that verdict is.
            ->orderByRaw('scanned_at DESC NULLS LAST')
            ->get()
            ->groupBy('manifest_id');

        $card = [];

        foreach ($manifests as $manifest) {
            $reports = $reportsByManifest->get($manifest->id);
            $report = $reports?->first();

            if ($report === null) {
                continue;
            }

            // Every finding behind an Ok report on THIS manifest — not only the newest
            // report's — because the actual pull-time enforcement,
            // ScanBlockGuard::blockingFinding(), reads every Ok report for a manifest, not
            // merely the latest. After a scanner swap, an older Ok report can still carry a
            // finding whose grace has long expired; reading only the newest report's
            // findings here would tell a customer "wird ausgeliefert" while the registry
            // keeps refusing the exact same pull on that older finding. `status`, `counts`
            // and the displayed `findings` list below stay scoped to the single newest
            // report — only the blocking DECISION unions across every Ok report.
            $blockingFindings = $reports
                ->filter(fn (OciScanReport $r): bool => $r->status === ScanStatus::Ok)
                ->flatMap(fn (OciScanReport $r): Collection => $r->findings);

            $card[$manifest->id] = $this->present($report, $blockingFindings, $group, $forCustomer);
        }

        return $card;
    }

    /**
     * @param  Collection<int, OciScanFinding>  $blockingFindings  every finding behind an Ok
     *                                                             report on this manifest,
     *                                                             across every scanner that
     *                                                             has ever produced one —
     *                                                             see forManifests()'s doc
     * @return array<string, mixed>
     */
    private function present(OciScanReport $report, Collection $blockingFindings, ?Group $group, bool $forCustomer): array
    {
        // The DISPLAYED findings stay scoped to the presented (newest) report — see
        // forManifests()'s doc on why the blocking decision below reads a wider set than
        // this. ScanBlockGuard::refuses() deliberately does not check a finding's report
        // status itself — it would have to lazy-load the report per finding on a
        // customer-facing page — so filtering to `Ok` here is what keeps `refuses()` from
        // ever being asked about a finding whose report never reached Ok, for either use.
        $findings = $report->status === ScanStatus::Ok
            ? $report->findings->sortBy([['severity_rank', 'desc'], ['first_seen_at', 'asc']])->values()
            : collect();

        $threshold = $group?->scan_block_severity;
        $blocked = false;
        $blocksAt = null;

        if ($group !== null && $threshold !== null) {
            // THE rule, asked through ScanBlockGuard::refuses() and nowhere else — never a
            // manual severity or grace-date comparison here, so this can never drift from
            // what a `docker pull` of this artifact actually decides. Checked over every
            // finding behind every Ok report on this manifest (see forManifests()), not
            // just the worst-severity one: severity and grace are independent axes, and the
            // worst-severity finding is not necessarily the one whose grace period expired
            // first.
            $blocked = $blockingFindings->contains(fn (OciScanFinding $f): bool => $this->guard->refuses($f, $group));

            // The half that makes the grace period legible: the EARLIEST instant any
            // qualifying finding starts (or started) blocking — ScanBlockGuard's own
            // shared method, the same one ScanBlockGuard::preview() uses, so the two
            // surfaces cannot name two different cut-off dates for the same data. Naming
            // the worst-SEVERITY finding's own date here (this presenter's first cut) could
            // land in the future while `blocked` above was already true — a self-
            // contradicting payload — because the worst-severity finding is not always the
            // one whose grace runs out first.
            $blocksAt = $this->guard->earliestBlockAt($blockingFindings, $threshold, $group->scan_block_grace_days);
        }

        return [
            'status' => $report->status->value,
            'status_label' => $report->status->label(),
            // Operator-internal — which product and version scanned this — withheld from
            // the customer entirely rather than left present-but-unused: an Inertia prop is
            // readable in the page JSON regardless of whether the template renders it.
            'scanner' => $forCustomer ? null : trim($report->scanner_name.' '.(string) $report->scanner_version),
            'scanned_at' => $report->scanned_at?->diffForHumans(),
            // A verdict that is trustworthy but out of date is its own state. Presenting it
            // as fresh is how an operator stops noticing a scanner that died weeks ago. The
            // FACT is never withheld from the customer, only the operator-internal STRING
            // that explains it — see `error` below.
            'stale' => $report->isStale(),
            // `ScanRunner::run()`'s failure text embeds the internal scanner URL, the
            // KONTORFIX_SCANNER_URL/KONTORFIX_SCANNER_ALLOWED_HOSTS env var names, an
            // operator instruction and raw cURL text. None of that is a customer's business
            // merely because their image happens to be stale — see CUSTOMER_FACING_ERROR.
            'error' => $forCustomer
                ? ($report->error !== null ? self::CUSTOMER_FACING_ERROR : null)
                : $report->error,
            'counts' => [
                'critical' => $report->critical_count,
                'high' => $report->high_count,
                'medium' => $report->medium_count,
                'low' => $report->low_count,
                'unknown' => $report->unknown_count,
            ],
            'blocked' => $blocked,
            'blocks_at' => $blocksAt?->toDateString(),
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
