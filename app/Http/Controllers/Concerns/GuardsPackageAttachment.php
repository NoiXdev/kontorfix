<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one implementation of the cross-tenant attach check.
 *
 * Packages carry no organization of their own — they belong to one through the registries
 * they are attached to. Every endpoint accepting `package_ids` must refuse ids that are not
 * already reachable by the caller, or attaching one would hand the caller write access to
 * another organization's package (repository_url and its embedded credentials, versions,
 * dists, resync, delete).
 *
 * A package attached to *no* registry is refused as well. It used to be exempt, on the
 * grounds that a freshly created package has no pivot rows yet — but both create paths
 * attach their `group_ids` in the same request, and a package created with none is invisible
 * to its own creator, since every package listing joins through `groups`. What the exemption
 * really covered was the orphan a deleted registry leaves behind when the pivot rows cascade:
 * claimable by any tenant who learned its id, together with everything already synced into
 * it, and unrecoverable for the original organization, whose own re-attach then trips the
 * foreign test. Orphans are attachable only by a caller whose scope spans every organization
 * — a super-admin, the party that has to adjudicate the ownership anyway.
 *
 * This trait exists because the check was previously written out twice, once in
 * {@see ScopesToAdministeredOrgs} for the web console and once in {@see ScopesApiToUser} for
 * `/api/v1`. The orphan exemption was removed from the web copy only, leaving the API half
 * claimable. The two surfaces still differ in *which* organizations count as the caller's —
 * the console intersects with the sidebar scope, the API uses the key owner's administered
 * organizations — so they keep their own resolution and share only the decision below.
 */
trait GuardsPackageAttachment
{
    /**
     * Aborts 403 unless every submitted package is already attached to a registry in one of
     * the given organizations. An empty `$orgIds` refuses every non-empty submission.
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
            ->whereDoesntHave('groups', fn (Builder $g) => $g->whereIn('organization_id', $orgIds))
            ->exists();

        abort_if($foreign, 403);
    }
}
