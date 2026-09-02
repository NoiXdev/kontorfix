<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Package;

/**
 * The one implementation of the cross-tenant attach check.
 *
 * A package is owned by an organization outright (`packages.organization_id`), so this is
 * a comparison rather than a reconstruction. It used to re-derive ownership from the
 * registries a package happened to be attached to, which could not answer for a package
 * attached to none: deleting a registry cascaded the pivot rows and left an orphan
 * claimable by any tenant who learned its id. Ownership survives the registries now, so
 * that case is simply a package owned by someone else.
 *
 * Both `assertCanAttachPackages()` callers (console and API) resolve the target
 * organization their own way — the console via the sidebar scope/`resolveCreationOrg()`,
 * the API via `resolveWriteOrg()` — but both pass this trait exactly the one organization
 * the attach targets, never the caller's broader reach. Attaching creates a `group_package`
 * row the enforcement migration requires to agree with `packages.organization_id`, so this
 * holds for every caller including a super-admin: there is no organization-spanning
 * exemption for the decision below, only for who may reach it.
 */
trait GuardsPackageAttachment
{
    /**
     * Aborts 403 unless every submitted package is owned by one of the given organizations.
     * An empty `$orgIds` refuses every non-empty submission. `assertCanAttachPackages()` in
     * both {@see ScopesToAdministeredOrgs} and {@see ScopesApiToUser} always calls this with
     * exactly one — the organization being attached into.
     *
     * @param  array<int, string>  $packageIds
     * @param  array<int, string>  $orgIds
     */
    protected function assertPackagesReachableIn(array $packageIds, array $orgIds): void
    {
        if ($packageIds === []) {
            return;
        }

        $foreign = Package::whereIn('id', $packageIds)
            ->whereNotIn('organization_id', $orgIds)
            ->exists();

        abort_if($foreign, 403);
    }
}
