<?php

namespace App\Support\Licence;

use Carbon\CarbonInterface;

/**
 * One organization's licence for one package: the version window plus the term.
 *
 * It exists so callers can tell the three states apart, which is the distinction this
 * whole feature is easiest to build backwards:
 *
 *   - no licence row at all → `organizationLicence()` returns null, and registry
 *     assignments stand on their own exactly as before this feature existed;
 *   - a live row → its bounds are the CEILING on every registry assignment;
 *   - an EXPIRED row → the package is served nowhere in this organization.
 *
 * The last one is the point: falling back to "no licence" when a term ends would make
 * expiry WIDEN access, which is the opposite of what a licence is for.
 */
final readonly class OrganizationLicence
{
    public function __construct(
        public VersionBounds $bounds,
        public ?CarbonInterface $availableUntil,
    ) {}

    /** A null term never expires — the same reading `group_package.available_until` has. */
    public function isExpired(): bool
    {
        return $this->availableUntil !== null && $this->availableUntil->isPast();
    }
}
