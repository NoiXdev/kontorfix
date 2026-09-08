<?php

namespace App\Services\Registry;

use App\Models\Organization;
use App\Models\RetentionPolicy;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;

/**
 * OCI-specific instance settings, resolved the same way RegistryTypeService resolves
 * registry types: the system setting is the instance-wide ceiling and an organization may
 * only restrict further within it, never widen past it.
 */
class OciSettings
{
    /** The instance-wide ceiling. */
    public function autoCreateGloballyEnabled(): bool
    {
        return SystemSetting::current()->oci_auto_create_repositories;
    }

    /**
     * Whether a push to an unregistered repository name may create that repository for the
     * given organization: the global value AND the organization's own, never either alone.
     *
     * Written as an intersection rather than as `$organization?->... ?? $global`, which
     * gets exactly one row wrong — global false, organization true — by letting an
     * organization switch on a feature the instance has switched off. `null` on the
     * organization is the inherit state and yields the ceiling unchanged.
     */
    public function autoCreateEnabledFor(?Organization $organization): bool
    {
        // Bound to a variable rather than written inline before `??`: `$a?->b ?? $c` reads
        // as the safe form but is flagged as redundant (isset() semantics already swallow
        // the null receiver), and `$a->b ?? $c` reads as an unguarded dereference.
        $narrowing = $organization?->oci_auto_create_repositories;

        return $this->autoCreateGloballyEnabled() && ($narrowing ?? true);
    }

    /**
     * The blob grace period, in hours. Instance-wide with NO per-organization narrowing —
     * unlike autoCreateEnabledFor() above, and deliberately: that is a feature an
     * organization may have less of, this is a safety margin on a delete path, and
     * "organization A's pushes get less protection than organization B's" is not a
     * preference anyone should be able to express.
     *
     * max(1, …) refuses a zero rather than reading it as "no protection": a sweep with no
     * cutoff deletes a layer out from under any push in flight.
     */
    public function blobGraceHours(): int
    {
        return max(1, SystemSetting::current()->oci_blob_grace_hours);
    }

    /**
     * Nothing created at or after this instant may be swept.
     *
     * A push uploads every blob first and writes the manifest last, so in between each
     * fresh blob is unreachable and looks exactly like garbage — a naive sweep would
     * delete a layer out from under a push in flight. The same holds for the child
     * manifests of a multi-arch image, which buildx writes by digest before the index.
     * The cutoff therefore has to outlast the longest realistic push, which is what the
     * setting's help text tells the operator tuning it.
     */
    public function graceCutoff(): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours($this->blobGraceHours());
    }

    /** The instance-wide default retention policy, or null when none is set. */
    public function defaultRetentionPolicy(): ?RetentionPolicy
    {
        return SystemSetting::current()->retentionPolicy;
    }
}
