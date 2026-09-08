<?php

namespace App\Services\Oci\Retention;

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Retention\RetentionDecision;
use App\Support\Retention\RetentionReport;
use App\Support\Retention\RetentionRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The policy layer's only writer, and its entire write surface is `delete from oci_tags`.
 * No manifest, no blob, no file — that separation is what makes the sweeper safe to reason
 * about (the policy knows nothing about blobs, the sweeper nothing about rules), so it is a
 * property of this class rather than a convention.
 *
 * A consequence every interface has to state in words: applying a policy frees NO disk
 * space at that moment. The manifests and blobs the removed tags reached become garbage the
 * sweeper collects after the grace period.
 */
class RetentionRunner
{
    public function __construct(
        private RetentionPolicyResolver $resolver,
        private RetentionEvaluator $evaluator,
    ) {}

    /** Null when no policy resolves — the package is never touched, not evaluated. */
    public function dryRun(Package $package): ?RetentionReport
    {
        $policy = $this->resolver->for($package);

        if ($policy === null) {
            return null;
        }

        return RetentionReport::for($package, $policy, $this->evaluator->decide(
            $this->rulesOf($policy),
            $package->ociTags()->get(),
            CarbonImmutable::now(),
        ));
    }

    public function apply(Package $package, ?User $causer = null): ?RetentionReport
    {
        // Captured BEFORE the evaluation reads a single tag: the delete below is guarded to
        // rows whose pushed_at is not newer than this instant.
        $evaluatedAt = CarbonImmutable::now();

        $report = $this->dryRun($package);

        if ($report === null || $report->removed() === []) {
            // No entry for a no-op. A daily run per package would otherwise bury the
            // entries that matter under an audit trail of nothing having happened.
            return $report;
        }

        // Deleted by name AND unchanged pushed_at, never by name alone. Between the
        // evaluation's read and this delete, a real `docker push` can re-point one of the
        // doomed tags — ManifestStore::put() stamps pushed_at on every push — and a
        // name-only delete would then remove the tag the client just pushed: worse than a
        // failed push, because the client believes it succeeded. The guard makes such a tag
        // fall out of the delete; it is evaluated afresh on the next run, as a new push
        // should be. Selected first (id + name) so the audit entry below records what was
        // ACTUALLY deleted, not what the evaluation intended.
        $deleted = $package->ociTags()
            ->whereIn('name', $report->removedTagNames())
            ->where('pushed_at', '<=', $evaluatedAt)
            ->get(['id', 'name']);

        if ($deleted->isEmpty()) {
            return $report;
        }

        $package->ociTags()->whereIn('id', $deleted->pluck('id'))->delete();

        $names = $deleted->pluck('name')->all();

        activity('retention')
            ->performedOn($package)
            ->causedBy($causer)
            ->event('retention_applied')
            // The names, not only the count: the description is what a reader skims, the
            // properties are what a reader who needs to know WHICH tag vanished opens.
            ->withProperties(['policy' => $report->policy->name, 'tags' => $names])
            ->log(sprintf(
                'Retention „%s“: %d Tag(s) entfernt',
                $report->policy->name,
                count($names),
            ));

        return $report;
    }

    /**
     * The editor panel's evaluation of rules that have not been saved — and may never be.
     * Takes the raw array straight from the form so the panel shows what the operator is
     * typing; RetentionRule::fromArray() is what refuses a rule that cannot be evaluated.
     *
     * @param  list<array<string, mixed>>  $rawRules
     * @return list<RetentionDecision>
     */
    public function previewWithRules(Package $package, array $rawRules): array
    {
        return $this->evaluator->decide(
            array_map(fn (array $raw): RetentionRule => RetentionRule::fromArray($raw), $rawRules),
            $package->ociTags()->get(),
            CarbonImmutable::now(),
        );
    }

    /** @return list<RetentionRule> */
    public function rulesOf(RetentionPolicy $policy): array
    {
        return array_map(fn (array $raw): RetentionRule => RetentionRule::fromArray($raw), $policy->rules);
    }

    /**
     * Every Docker package this policy applies to, through either tier.
     *
     * The instance default's set is "no policy of their own", which is why this cannot be
     * `$policy->packages()`: as the default, a policy also governs packages that never name
     * it — see the relation's own docblock.
     *
     * @return Builder<Package>
     */
    public function packagesFor(RetentionPolicy $policy): Builder
    {
        $query = Package::query()->where('type', PackageType::Docker);

        if (SystemSetting::current()->retention_policy_id === $policy->id) {
            return $query->where(fn (Builder $inner) => $inner
                ->where('retention_policy_id', $policy->id)
                ->orWhereNull('retention_policy_id'));
        }

        return $query->where('retention_policy_id', $policy->id);
    }
}
