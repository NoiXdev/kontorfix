<?php

namespace App\Services\Registry;

use App\Models\Organization;
use App\Models\SystemSetting;

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
}
