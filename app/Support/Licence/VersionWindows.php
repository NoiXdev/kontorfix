<?php

namespace App\Support\Licence;

/**
 * A licence's full set of version windows — today always a single `VersionBounds` read off
 * one assignment row, but a list rather than a lone value so a later licence made of several
 * windows (e.g. stacked assignments) is additive, not a reshape.
 */
final readonly class VersionWindows
{
    /** @param list<VersionBounds> $windows */
    public function __construct(
        public array $windows,
    ) {}

    /**
     * Unlimited only if some window in the list explicitly grants every version — never
     * merely because the list is EMPTY.
     *
     * An empty list means no unexpired assignment was found to build a window from (e.g.
     * one lapsed, or was revoked, between two separate `now()` reads on the serving path —
     * `RegistryAccessService::organizationPackage()`'s and this class's own consumer,
     * `VersionEntitlement::windowsForOrganization()`), and that must refuse every version,
     * not fail open and admit everything. "Unlimited" is stated only as an explicit member
     * of the list — {@see unlimited()} below, or a `VersionBounds::fromPivot(null, null)`
     * a caller mapped in for a genuinely unbounded assignment — never inferred from the
     * list's shape.
     */
    public function isUnlimited(): bool
    {
        foreach ($this->windows as $window) {
            if ($window->isUnlimited()) {
                return true;
            }
        }

        return false;
    }

    /** A single explicit unlimited member, never an empty list — see isUnlimited()'s docblock. */
    public static function unlimited(): self
    {
        return new self([VersionBounds::unlimited()]);
    }
}
