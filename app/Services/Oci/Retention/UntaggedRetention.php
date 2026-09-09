<?php

namespace App\Services\Oci\Retention;

use App\Enums\PackageType;
use App\Models\Package;
use Carbon\CarbonImmutable;

/**
 * The policy side of the keep_untagged rule: per package, the instant before which an
 * untagged manifest may be collected. This class is the whole hand-off — the sweeper
 * consumes the returned timestamps and never sees a rule, which is what keeps the
 * policy/sweeper separation alive after the rule type that threatens it most.
 */
class UntaggedRetention
{
    public function __construct(private RetentionPolicyResolver $resolver) {}

    /**
     * package_id ⇒ cutoff, for every Docker package of the organization whose resolved
     * rules carry a keep_untagged window. Packages without one are absent — absence means
     * "the grace period alone decides", exactly as before the rule existed.
     *
     * Resolved per package (the chain includes inline rules and the instance default), so
     * this walks the organization's Docker packages once per sweep. One resolver call per
     * package is the cost of the four-tier chain; the sweep is a nightly batch job, not a
     * request path.
     *
     * Deliberately does NOT catch CorruptInlineRetentionRules: unlike `oci:retention` (which
     * catches it per package and skips only that one), a package silently skipped HERE would
     * lose its keep_untagged protection for the whole organization's sweep — OciSweeper
     * builds one reachability graph per organization from every package's window at once, so
     * "skip the corrupt package" would mean "delete the manifests its rule was meant to
     * keep, alongside everyone else's". Letting the exception propagate aborts the sweep
     * instead, which is the safe direction. See CorruptInlineRetentionRules's docblock.
     *
     * @return array<string, CarbonImmutable>
     */
    public function windowsFor(string $organizationId): array
    {
        $windows = [];

        $packages = Package::query()
            ->where('organization_id', $organizationId)
            ->where('type', PackageType::Docker)
            ->get();

        foreach ($packages as $package) {
            $days = $this->resolver->for($package)?->untaggedKeepDays();

            if ($days !== null) {
                $windows[$package->id] = CarbonImmutable::now()->subDays($days);
            }
        }

        return $windows;
    }
}
