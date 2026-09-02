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
 * The two surfaces still differ in *which* organizations count as the caller's — the
 * console intersects with the sidebar scope, the API uses the key owner's administered
 * organizations — so they keep their own resolution and share only the decision below.
 */
trait GuardsPackageAttachment
{
    /**
     * Aborts 403 unless every submitted package is owned by one of the given organizations.
     * An empty `$orgIds` refuses every non-empty submission.
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
