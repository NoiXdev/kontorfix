<?php

namespace App\Services\Oci\Retention;

use App\Enums\PackageType;
use App\Models\Package;
use App\Models\SystemSetting;
use App\Support\Retention\ResolvedRetention;
use App\Support\Retention\RetentionRule;

/**
 * Which rules apply to a package. An ordered list of tiers, first non-null wins:
 *
 *   1. INLINE rules on the package itself — anonymous, authored by the owning
 *      organization's admin, valid only there. Tier 0 by operator decision: the package
 *      tier wins unconditionally, which makes the instance default a default rather than
 *      a mandate.
 *   2. The package's named policy.
 *   3. The instance default.
 *   4. Nothing — the runner never touches the package.
 *
 * Written as a list rather than a `??` chain so a further tier slots in as one array
 * element; no call site changes.
 *
 * Invalid inline rules THROW rather than resolving to "no rules": a corrupt jsonb value
 * must not silently read as "keep everything" (the feature stops working unnoticed) nor as
 * an empty OR (everything unshielded is removed). RetentionRule::fromArray() is the one
 * grammar; surfacing its refusal beats guessing on a delete path.
 */
class RetentionPolicyResolver
{
    public function for(Package $package): ?ResolvedRetention
    {
        // Retention operates on tags, and no other package type has any. The filter lives
        // here so every caller inherits it — a resolver that answered "these rules apply"
        // for a composer package would be a lie every surface repeats.
        if ($package->type !== PackageType::Docker) {
            return null;
        }

        foreach ($this->tiers() as $tier) {
            $resolved = $tier($package);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /** @return list<callable(Package): ?ResolvedRetention> */
    private function tiers(): array
    {
        return [
            fn (Package $package): ?ResolvedRetention => $package->retention_rules === null ? null : new ResolvedRetention(
                array_map(fn (array $raw): RetentionRule => RetentionRule::fromArray($raw), $package->retention_rules),
                ResolvedRetention::TIER_INLINE,
                null,
            ),
            fn (Package $package): ?ResolvedRetention => $package->retentionPolicy === null ? null : new ResolvedRetention(
                array_map(fn (array $raw): RetentionRule => RetentionRule::fromArray($raw), $package->retentionPolicy->rules),
                ResolvedRetention::TIER_PACKAGE,
                $package->retentionPolicy,
            ),
            // An organization tier would slot in HERE, ahead of the instance default.
            function (Package $package): ?ResolvedRetention {
                $default = SystemSetting::current()->retentionPolicy;

                return $default === null ? null : new ResolvedRetention(
                    array_map(fn (array $raw): RetentionRule => RetentionRule::fromArray($raw), $default->rules),
                    ResolvedRetention::TIER_INSTANCE,
                    $default,
                );
            },
        ];
    }
}
