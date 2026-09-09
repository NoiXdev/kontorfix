<?php

namespace App\Support\Retention;

use RuntimeException;
use Throwable;

/**
 * One package's `retention_rules` jsonb failed to parse into RetentionRule objects. Wraps
 * whatever RetentionRule::fromArray() threw (a ValueError for an unknown rule type, an
 * InvalidArgumentException for a malformed field) with the one thing neither carries on its
 * own: WHICH package. Thrown by RetentionPolicyResolver so a corrupt row is reported with an
 * actionable message instead of surfacing as a bare "\"foo\" is not a valid backing value
 * for enum RetentionRuleType" with no clue which package hit it.
 *
 * The spec's ruling stands: corrupt inline rules THROW, never resolve to "no rules" — see
 * RetentionPolicyResolver's own class docblock. The two callers reached through the
 * resolver act on that throw in OPPOSITE directions, both deliberately:
 *
 *   - `oci:retention` (ApplyOciRetention) catches this PER PACKAGE: reports the package
 *     loudly, deletes nothing for it, and continues with the rest of the run. A corrupt row
 *     must not silently read as "delete freely" for every OTHER package too.
 *   - The keep_untagged sweeper (UntaggedRetention::windowsFor(), and OciSweeper which
 *     calls it once per organization) does NOT catch this — see that class's own docblock
 *     note. A package silently skipped there would lose its keep_untagged protection for
 *     the whole organization's sweep, deleting manifests the corrupt rule was meant to
 *     keep. Letting the sweep abort is the safe direction.
 */
final class CorruptInlineRetentionRules extends RuntimeException
{
    public function __construct(
        public readonly string $packageId,
        public readonly string $packageName,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'Package "%s" (%s) has invalid inline retention rules: %s',
                $packageName,
                $packageId,
                $previous->getMessage(),
            ),
            previous: $previous,
        );
    }
}
