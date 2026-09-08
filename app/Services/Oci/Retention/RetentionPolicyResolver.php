<?php

namespace App\Services\Oci\Retention;

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;

/**
 * Which policy applies to a package. An ordered list of tiers, first non-null wins.
 *
 * Written as a list rather than as a `??` chain because the design commits to an
 * organization tier slotting in without restructuring: adding it is one array element in
 * tiers() below, in the right position, and no call site changes. A `??` chain would bury
 * the ordering in an expression that has to be re-read to be extended correctly.
 *
 * Returns null for "no policy resolved", which the runner treats as "never touch this
 * package" — NOT as an empty policy and not as keep-everything. Both tiers ship unset, so
 * null is what a fresh installation resolves for every package.
 */
class RetentionPolicyResolver
{
    public function for(Package $package): ?RetentionPolicy
    {
        // Retention operates on tags, and no other package type has any. The column stays
        // generic; the type filter lives here so every caller inherits it — a resolver
        // that answered "this policy applies" for a composer package would be a lie every
        // surface (admin card, portal section) repeats.
        if ($package->type !== PackageType::Docker) {
            return null;
        }

        foreach ($this->tiers() as $tier) {
            $policy = $tier($package);

            if ($policy !== null) {
                return $policy;
            }
        }

        return null;
    }

    /** @return list<callable(Package): ?RetentionPolicy> */
    private function tiers(): array
    {
        return [
            fn (Package $package): ?RetentionPolicy => $package->retentionPolicy,
            // An organization tier would slot in HERE, ahead of the instance default.
            fn (Package $package): ?RetentionPolicy => SystemSetting::current()->retentionPolicy,
        ];
    }
}
