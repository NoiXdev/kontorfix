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
     * What a proposed threshold would do to this registry, BEFORE it is saved.
     *
     * This exists because switching blocking on can refuse a customer's pull, and an
     * operator should not have to guess how many customers they are about to affect.
     * `blocking_later` is the half that makes the grace period legible: those artifacts are
     * not refused today, and the date says when that changes.
     *
     * @return array{threshold: ?string, grace_days: int, blocking_now: int, blocking_later: int, artifacts: list<array<string, mixed>>}
     */
    public function preview(Group $group, ?VulnerabilitySeverity $threshold, int $graceDays): array
    {
        $empty = [
            'threshold' => $threshold?->value,
            'grace_days' => $graceDays,
            'blocking_now' => 0,
            'blocking_later' => 0,
            'artifacts' => [],
        ];

        if ($threshold === null) {
            return $empty;
        }

        $packageIds = $group->packages()
            ->where('packages.type', PackageType::Docker)
            ->pluck('packages.id');

        if ($packageIds->isEmpty()) {
            return $empty;
        }

        // Every candidate, grace ignored — the grace decides which COLUMN a row lands in,
        // not whether it is shown. An operator has to see what is coming, not only what has
        // already arrived.
        $candidates = $this->atOrAboveThreshold($threshold, graceDays: null)
            ->with(['report.manifest.package:id,name'])
            ->whereHas('report', fn (Builder $q) => $q
                ->where('status', ScanStatus::Ok)
                ->whereHas('manifest', fn (Builder $m) => $m->whereIn('package_id', $packageIds)))
            ->orderByDesc('severity_rank')
            ->orderBy('first_seen_at')
            ->get();

        $tagsByManifest = OciTag::whereIn('manifest_id', $candidates->pluck('report.manifest_id')->unique())
            ->get()
            ->groupBy('manifest_id')
            ->map(fn ($tags) => $tags->pluck('name')->sort()->values()->all());

        $now = now();
        $artifacts = [];

        // One row per MANIFEST, keyed on its worst finding — the ordering above means the
        // first one seen for a manifest is the one to report. A list with one row per CVE
        // would show the same image thirty times and tell the operator nothing extra.
        foreach ($candidates as $finding) {
            $manifest = $finding->report?->manifest;

            if ($manifest === null || isset($artifacts[$manifest->id])) {
                continue;
            }

            $blocksAt = $finding->blocksAt($graceDays);

            $artifacts[$manifest->id] = [
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

        $artifacts = array_values($artifacts);

        return [
            'threshold' => $threshold->value,
            'grace_days' => $graceDays,
            'blocking_now' => count(array_filter($artifacts, fn (array $a): bool => $a['blocked'])),
            'blocking_later' => count(array_filter($artifacts, fn (array $a): bool => ! $a['blocked'])),
            'artifacts' => $artifacts,
        ];
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
        $query = OciScanFinding::query()->where('severity_rank', '>=', $threshold->rank());

        if ($graceDays !== null) {
            $query->where('first_seen_at', '<=', now()->subDays($graceDays));
        }

        return $query;
    }
}
