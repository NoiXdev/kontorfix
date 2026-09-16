<?php

namespace App\Services\Scanner;

use App\Enums\PackageType;
use App\Enums\ScanStatus;
use App\Enums\VulnerabilitySeverity;
use App\Models\Group;
use App\Models\OciManifest;
use App\Models\OciScanFinding;
use App\Models\OciTag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Whether a registry refuses to serve an artifact, and what the operator would be doing to
 * their customers by switching that on.
 *
 * The SEVERITY half of the rule is asked twice through one statement: the refusal and the
 * preview both filter through `atOrAboveThreshold()`, because a preview that used its own
 * severity comparison could disagree with the outcome — and a preview nobody can trust is
 * worse than none, since the operator acts on it. This is the same discipline the licence
 * preview already follows. The GRACE half cannot share a single statement the same way — SQL
 * needs `first_seen_at <= now() - grace` to stay indexable, while `preview()` needs every
 * candidate regardless of grace (grace only decides which column a row lands in) and
 * `refuses()` needs an in-memory comparison over an already-loaded model — so both non-SQL
 * call sites collapse onto `OciScanFinding::blocksAt()` instead: that method is the one place
 * "known since when" turns into "blocked as of when", and every caller but the SQL query itself
 * goes through it.
 *
 * Three facts decide a refusal, and none of them is "is this image bad":
 *
 *  - the registry names a threshold (null, the default, means nothing is ever refused);
 *  - a SUCCESSFUL scan reported a finding at or above it;
 *  - that finding has been known — `first_seen_at`, not scan time — longer than the grace.
 *
 * The rule is still stated a SECOND time for the grace half, unavoidably, in `refuses()`: SQL's
 * `first_seen_at <= now() - grace` and `blocksAt($graceDays)->lte(now())` cannot literally be
 * one statement. `refuses()` is kept beside the SQL query rather than in, say,
 * ScanCardPresenter, precisely so a future edit to the rule sees both statements of it in one
 * file — and the boundary-agreement test below is what keeps the two honest.
 */
final class ScanBlockGuard
{
    /**
     * How many artifacts `preview()` NAMES at most.
     *
     * The list is an illustration, not an inventory: it exists so the operator can recognise
     * what they are about to affect, and fifty rows is already more than anyone reads. The
     * COUNTS beside it are exact regardless, and `artifacts_total` is what lets the screen
     * say how much it is not showing.
     */
    private const ARTIFACT_LIMIT = 50;

    /**
     * The worst finding that refuses this artifact in this registry, or null.
     *
     * Returns BEFORE touching the database when the registry does not block, which is the
     * shipped default: this runs on every manifest resolution, and a feature nobody enabled
     * must not add a query to every `docker pull` on the instance.
     */
    public function blockingFinding(OciManifest $manifest, Group $group): ?OciScanFinding
    {
        $threshold = $group->scan_block_severity;

        if ($threshold === null) {
            return null;
        }

        return $this->atOrAboveThreshold($threshold, $group->scan_block_grace_days)
            ->whereHas('report', fn (Builder $q) => $q
                ->where('manifest_id', $manifest->id)
                ->where('status', ScanStatus::Ok))
            // Worst first, so the error names the finding the customer should act on rather
            // than whichever row the index happened to reach first.
            ->orderByDesc('severity_rank')
            ->orderBy('first_seen_at')
            ->first();
    }

    /**
     * The same rule as `blockingFinding()`, restated over a finding already loaded into
     * memory rather than reissued as a query — for Task 7's ScanCardPresenter, which
     * displays findings it already fetched and must not query the database again just to
     * ask "would this block?" of a row it is already holding.
     *
     * `$finding->severity` and `$finding->severity_rank` say the same thing by construction
     * (`OciScanFinding::booted()`), so comparing `severity` against the threshold here
     * mirrors `severity_rank >= $threshold->rank()` in `atOrAboveThreshold()` exactly. The
     * grace half goes through `OciScanFinding::blocksAt()` — the same method `preview()` uses
     * for its own `blocked` column — rather than re-deriving `first_seen_at` plus `grace_days`
     * a third time. This does NOT check the finding's report status — the caller is expected
     * to already be looking at findings from an `Ok` report, the same way `preview()`'s
     * `whereHas` filters before any finding reaches this comparison.
     */
    public function refuses(OciScanFinding $finding, Group $group): bool
    {
        $threshold = $group->scan_block_severity;

        if ($threshold === null) {
            return false;
        }

        return $finding->severity->atLeast($threshold)
            && $finding->blocksAt($group->scan_block_grace_days)->lte(now());
    }

    /**
     * The earliest instant ANY of the given findings would start refusing the artifact under
     * this threshold and grace — the MINIMUM of `blocksAt()` over every finding at or above
     * the threshold. Null when none qualify.
     *
     * This is deliberately NOT "the worst-severity finding's own `blocksAt()`", which is what
     * `preview()` and Task 7's `ScanCardPresenter` each used to compute independently before
     * this method existed, and both got it wrong the same way: severity and "how long known"
     * are independent axes, so the worst-severity finding is not necessarily the one whose
     * grace runs out soonest. A High seen 25 days ago under a 30-day grace starts blocking in
     * 5 days; a Critical seen yesterday under the same grace starts blocking in 29. Naming the
     * Critical's date — because it sorts first by severity — told the reader a cut-off the
     * registry does not actually honour, and in the worse case left `blocked: true` sitting
     * next to a `blocks_at` that still reads as the future.
     *
     * The minimum is correct by construction: it IS the instant the first of these findings'
     * `refuses()` flips from false to true, so `blocked` (computed via `refuses()` over the
     * same set) and `blocks_at` (computed here) can never contradict each other.
     *
     * @param  iterable<OciScanFinding>  $findings
     */
    public function earliestBlockAt(iterable $findings, VulnerabilitySeverity $threshold, int $graceDays): ?Carbon
    {
        $dates = [];

        foreach ($findings as $finding) {
            if ($finding->severity->atLeast($threshold)) {
                $dates[] = $finding->blocksAt($graceDays);
            }
        }

        return $dates === [] ? null : min($dates);
    }

    /**
     * What a proposed threshold would do to this registry, BEFORE it is saved.
     *
     * This exists because switching blocking on can refuse a customer's pull, and an
     * operator should not have to guess how many customers they are about to affect.
     * `blocking_later` is the half that makes the grace period legible: those artifacts are
     * not refused today, and the date says when that changes.
     *
     * BOUNDED, in every direction, because of where this runs. The settings screen fires it
     * on a debounced keystroke — up to ten times a minute per operator — against the same
     * database the pull path uses, and a Debian-based image routinely carries 500-1500 CVEs.
     * Hydrating every qualifying finding in the registry (50 repositories x 20 manifests is
     * 10^5-10^6 rows) turned the one screen the operator needs before switching blocking on
     * into a 500. So:
     *
     *  - `blocking_now` / `blocking_later` are COUNT queries over distinct manifests. They
     *    stay exact however large the registry is, and no row is hydrated to produce them.
     *  - the NAMED list is capped at ARTIFACT_LIMIT manifests, chosen worst-first by a
     *    grouped query that returns at most that many ids and hydrates nothing.
     *  - the two per-manifest facts each row needs — which finding to name, and which one's
     *    grace runs out first — come from ONE row per manifest each, via Postgres
     *    `DISTINCT ON`, never from the manifest's whole finding set. So a manifest with 1500
     *    findings costs two rows here, not 1500, and `blocks_at` is still computed over the
     *    real earliest-known finding rather than over an arbitrarily truncated subset (which
     *    is how a capped list would otherwise start contradicting `blocking_now`).
     *
     * `artifacts_total` is what lets the screen say "… und N weitere" honestly: the list is
     * a bounded view, the counts are the whole registry.
     *
     * @return array{threshold: ?string, grace_days: int, blocking_now: int, blocking_later: int, artifacts_total: int, artifacts: list<array<string, mixed>>}
     */
    public function preview(Group $group, ?VulnerabilitySeverity $threshold, int $graceDays): array
    {
        $empty = [
            'threshold' => $threshold?->value,
            'grace_days' => $graceDays,
            'blocking_now' => 0,
            'blocking_later' => 0,
            'artifacts_total' => 0,
            'artifacts' => [],
        ];

        if ($threshold === null) {
            return $empty;
        }

        // assignedPackages(), not packages(): every serve-time predicate in this codebase
        // asks the expiry-filtered relation (see Group::assignedPackages()'s own docblock,
        // which names itself the one statement of that rule), and a preview counting images
        // this registry has already stopped serving would put the operator back to guessing
        // which of the listed artifacts the change actually affects.
        $packageIds = $group->assignedPackages()
            ->where('packages.type', PackageType::Docker)
            ->pluck('packages.id');

        if ($packageIds->isEmpty()) {
            return $empty;
        }

        // Exact, and independent of the capped list below. Grace applied = what would be
        // refused the moment this is saved; grace ignored = everything that qualifies at
        // all, so the difference is what is still inside its grace period.
        $blockingNow = $this->qualifyingManifestCount($packageIds, $threshold, $graceDays);
        $blockingTotal = $this->qualifyingManifestCount($packageIds, $threshold, null);

        $manifestIds = $this->worstManifestIds($packageIds, $threshold);

        // The finding each row NAMES — worst severity, earliest first_seen breaking a tie.
        // Naming which CVE to show is a different question from `blocks_at`: it does not
        // have to be the finding whose grace runs out first, only the one worth telling the
        // operator to act on.
        $named = $this->onePerManifest($manifestIds, $threshold, worstFirst: true);
        // The finding whose grace runs out FIRST — see earliestBlockAt()'s own doc for why
        // that is not always the worst-severity one.
        $earliest = $this->onePerManifest($manifestIds, $threshold, worstFirst: false);

        $manifests = OciManifest::with('package:id,name')->whereIn('id', $manifestIds)->get()->keyBy('id');

        $tagsByManifest = OciTag::whereIn('manifest_id', $manifestIds)
            ->get()
            ->groupBy('manifest_id')
            ->map(fn ($tags) => $tags->pluck('name')->sort()->values()->all());

        $now = now();
        $artifacts = [];

        foreach ($manifestIds as $manifestId) {
            $manifest = $manifests->get($manifestId);
            $finding = $named->get($manifestId);
            $earliestFinding = $earliest->get($manifestId);

            // A report row whose manifest no longer resolves names nothing to show. Cannot
            // normally happen — the ranking query inner-joins oci_manifests — but this stays
            // honest about it rather than asserting it with a non-null assumption.
            if ($manifest === null || $finding === null || $earliestFinding === null) {
                continue;
            }

            // Through the SAME shared method ScanCardPresenter uses, over the one finding the
            // database already reduced to the minimum — so the operator's preview and the
            // customer-facing card cannot name two different cut-off dates for one image.
            $blocksAt = $this->earliestBlockAt([$earliestFinding], $threshold, $graceDays);

            if ($blocksAt === null) {
                continue;
            }

            $artifacts[] = [
                'package' => $manifest->package?->name,
                'digest' => $manifest->digest,
                'tags' => $tagsByManifest->get($manifest->id, []),
                'vulnerability_id' => $finding->vulnerability_id,
                'severity' => $finding->severity->value,
                'severity_label' => $finding->severity->label(),
                'blocked' => $blocksAt->lte($now),
                'blocks_at' => $blocksAt->toDateString(),
            ];
        }

        return [
            'threshold' => $threshold->value,
            'grace_days' => $graceDays,
            'blocking_now' => $blockingNow,
            'blocking_later' => max(0, $blockingTotal - $blockingNow),
            'artifacts_total' => $blockingTotal,
            'artifacts' => $artifacts,
        ];
    }

    /**
     * How many DISTINCT manifests of these packages carry a qualifying finding.
     *
     * A count query rather than `count()` over a hydrated collection, so the two numbers the
     * operator actually acts on stay exact no matter how far the listed artifacts are capped.
     * Joined rather than `whereHas`ed because the join to `oci_manifests` is also what drops
     * a report row whose manifest is gone.
     *
     * @param  Collection<int, string>  $packageIds
     * @param  int|null  $graceDays  null = ignore the grace, i.e. everything that qualifies at all
     */
    private function qualifyingManifestCount(Collection $packageIds, VulnerabilitySeverity $threshold, ?int $graceDays): int
    {
        return $this->qualifying($packageIds, $threshold, $graceDays)
            ->distinct()
            ->count('oci_scan_reports.manifest_id');
    }

    /**
     * The manifests the preview NAMES, worst first — at most ARTIFACT_LIMIT of them.
     *
     * Grouped in the database and plucked as bare ids: picking which manifests to show must
     * not itself hydrate the finding set this method exists to avoid loading.
     *
     * @param  Collection<int, string>  $packageIds
     * @return list<string>
     */
    private function worstManifestIds(Collection $packageIds, VulnerabilitySeverity $threshold): array
    {
        /** @var list<string> $ids */
        $ids = $this->qualifying($packageIds, $threshold, graceDays: null)
            ->groupBy('oci_scan_reports.manifest_id')
            ->select('oci_scan_reports.manifest_id')
            ->selectRaw('max(oci_scan_findings.severity_rank) as worst_rank, min(oci_scan_findings.first_seen_at) as earliest_seen')
            ->orderByDesc('worst_rank')
            ->orderBy('earliest_seen')
            ->limit(self::ARTIFACT_LIMIT)
            ->pluck('manifest_id')
            ->all();

        return $ids;
    }

    /**
     * Exactly one qualifying finding per manifest, via Postgres `DISTINCT ON`.
     *
     * The whole point is the row count: with `$worstFirst` the row is the one the preview
     * names, without it the row is the one whose grace expires first, and either way a
     * manifest carrying 1500 findings contributes one row rather than 1500.
     *
     * @param  list<string>  $manifestIds
     * @return Collection<string, OciScanFinding> keyed by manifest id
     */
    private function onePerManifest(array $manifestIds, VulnerabilitySeverity $threshold, bool $worstFirst): Collection
    {
        if ($manifestIds === []) {
            return collect();
        }

        $query = $this->atOrAboveThreshold($threshold, graceDays: null)
            ->join('oci_scan_reports', 'oci_scan_reports.id', '=', 'oci_scan_findings.report_id')
            ->where('oci_scan_reports.status', ScanStatus::Ok)
            ->whereIn('oci_scan_reports.manifest_id', $manifestIds)
            ->select('oci_scan_findings.*', 'oci_scan_reports.manifest_id as preview_manifest_id')
            ->distinct(['oci_scan_reports.manifest_id'])
            // `DISTINCT ON` requires its own expression to lead the ordering; what follows it
            // is what decides WHICH row of each manifest survives.
            ->orderBy('oci_scan_reports.manifest_id');

        $query = $worstFirst
            ? $query->orderByDesc('oci_scan_findings.severity_rank')->orderBy('oci_scan_findings.first_seen_at')
            : $query->orderBy('oci_scan_findings.first_seen_at')->orderByDesc('oci_scan_findings.severity_rank');

        /** @var Collection<string, OciScanFinding> $rows */
        $rows = $query->get()->keyBy('preview_manifest_id');

        return $rows;
    }

    /**
     * Every qualifying finding of these packages, as a joined query — the shared body of the
     * count, the ranking and (via atOrAboveThreshold) the per-manifest reads above, so the
     * three can never come to disagree about which finding qualifies.
     *
     * @param  Collection<int, string>  $packageIds
     * @return Builder<OciScanFinding>
     */
    private function qualifying(Collection $packageIds, VulnerabilitySeverity $threshold, ?int $graceDays): Builder
    {
        return $this->atOrAboveThreshold($threshold, $graceDays)
            ->join('oci_scan_reports', 'oci_scan_reports.id', '=', 'oci_scan_findings.report_id')
            ->join('oci_manifests', 'oci_manifests.id', '=', 'oci_scan_reports.manifest_id')
            ->where('oci_scan_reports.status', ScanStatus::Ok)
            ->whereIn('oci_manifests.package_id', $packageIds);
    }

    /**
     * The one predicate. `severity_rank` rather than a set of severity strings, because this
     * runs on the pull path and the index on (report_id, severity_rank, first_seen_at) is
     * built for exactly this comparison.
     *
     * @param  int|null  $graceDays  null = ignore the grace entirely (the preview's "what is coming" view)
     * @return Builder<OciScanFinding>
     */
    private function atOrAboveThreshold(VulnerabilitySeverity $threshold, ?int $graceDays): Builder
    {
        // Table-qualified: preview()'s reads join `oci_scan_reports` and `oci_manifests` into
        // the same query, and an unqualified column name there is one added column away from
        // becoming ambiguous — or, worse, from silently binding to the wrong table.
        $query = OciScanFinding::query()->where('oci_scan_findings.severity_rank', '>=', $threshold->rank());

        if ($graceDays !== null) {
            $query->where('oci_scan_findings.first_seen_at', '<=', now()->subDays($graceDays));
        }

        return $query;
    }
}
