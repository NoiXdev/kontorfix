<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Package;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The one implementation of the cross-tenant attach check.
 *
 * A package is reachable from a registry when its organization owns it, OR when it is
 * marked `shared` AND the caller administers the organization that owns it — a package of
 * the operator organization deliberately offered to a tenant BY the operator. Ownership is
 * therefore no longer the whole rule; it is the rule for everything the operator has not
 * explicitly opened up, and the opening is the operator's act rather than the tenant's.
 *
 * SHARING AND ASSIGNING ARE TWO GATES, DELIBERATELY (spec §4). `shared` says a package MAY
 * leave its organization; the assignment says which customer actually receives it, and the
 * operator makes that decision per customer. An earlier version of this trait admitted
 * `own OR shared` for everyone, which collapsed the two: any customer admin could help
 * themselves to any shared package, so `shared` meant "available to whoever finds it" and
 * the per-customer control the decision exists to provide was not enforced at all. Only a
 * caller who administers the owning organization may now cross that line — for a super
 * admin that is every organization, so an operator login is unaffected.
 *
 * Only an operator-organization package can be marked shared
 * (Admin\PackageController::shared), so administering the owner is in practice
 * administering the operator organization. The check is nevertheless stated over the
 * package's actual `organization_id` rather than over `is_operator`: it is the row's owner
 * that the exception is granted against, and a rule that asked a different question would
 * have to be kept in step with the sharing gate by hand.
 *
 * Reachability is not the whole of the shared decision either: a shared package may not
 * shadow a customer's own package of the same name. That refusal lives in
 * App\Services\Package\SharedAssignment and must be asked separately by every caller that
 * writes a `group_package` row.
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
     * Aborts 403 unless every submitted package is owned by one of the given organizations,
     * or is shared and owned by an organization the caller administers. An empty `$orgIds`
     * still refuses every non-empty submission of packages the caller cannot reach.
     * `assertCanAttachPackages()` in both {@see ScopesToAdministeredOrgs} and
     * {@see ScopesApiToUser} always calls this with exactly one — the organization being
     * attached into.
     *
     * @param  array<int, string>  $packageIds
     * @param  array<int, string>  $orgIds
     */
    protected function assertPackagesReachableIn(array $packageIds, array $orgIds): void
    {
        if ($packageIds === []) {
            return;
        }

        $administeredOrgIds = $this->administeredOrganizationIds();

        $foreign = Package::whereIn('id', $packageIds)
            ->whereNotIn('organization_id', $orgIds)
            // Nested, so the two halves of the shared exception cannot be split by the
            // `or`: a package is only excused from the ownership rule when it is BOTH
            // shared and owned by an organization this caller administers.
            ->where(fn (Builder $unreachable) => $unreachable
                ->where('shared', false)
                ->orWhereNotIn('organization_id', $administeredOrgIds))
            ->exists();

        abort_if($foreign, 403);
    }

    /**
     * Aborts 403 if any of the given packages is shared and owned by an organization the
     * caller does not administer.
     *
     * The other half of spec §4's second gate. {@see assertPackagesReachableIn} answers it
     * for a write that CREATES an assignment; this one answers it for a write that ends or
     * alters an existing one — detaching, and re-dating `group_package.available_until`.
     * Both are decisions about which customer receives the operator's package and for how
     * long, so both belong to the operator; a customer admin who could detach could
     * withdraw a package their own builds depend on, and one who could re-date could push a
     * lapsed share back into force or end a live one.
     *
     * Stated over the packages a write touches rather than over the direction of the write,
     * so an endpoint that expresses a detach by OMISSION (the API's `sync()`) asks the same
     * question by naming what it would drop.
     *
     * Nothing here re-asks reachability: the pivot row already exists, so it passed
     * {@see assertPackagesReachableIn} when it was written.
     *
     * THE `shared` CLAUSE CANNOT BE PINNED BY A TEST, and that is a property of the
     * ownership invariant rather than a gap. Distinguishing it would need an existing
     * assignment of a NON-shared package owned outside the caller's administration, and no
     * such row is reachable: v0.8.0 lets a package be assigned only to registries of its own
     * organization, un-sharing is refused while any cross-organization assignment survives,
     * and anyone who administers a registry administers the organization that owns its
     * non-shared packages. Replacing `true` with `false` therefore reddens exactly the tests
     * that dropping the whole check reddens (mutations M3 and M4a of the task-6b report).
     * The clause is kept anyway: without it this method would silently also enforce the
     * ownership rule, which is neither what its name says nor what spec §4 asks of it, and a
     * guard whose stated rule and actual rule differ is how this branch's defects started.
     *
     * @param  array<int, string>  $packageIds
     */
    protected function assertMayManageSharedAssignments(array $packageIds): void
    {
        if ($packageIds === []) {
            return;
        }

        $refused = Package::whereIn('id', $packageIds)
            ->where('shared', true)
            ->whereNotIn('organization_id', $this->administeredOrganizationIds())
            ->exists();

        abort_if($refused, 403);
    }

    /**
     * The organizations the current caller administers — the set both decisions above are
     * measured against, and the one {@see ScopesToAdministeredOrgs::scopeAssignablePackageQuery()}
     * builds the picker from, so the guard and the picker cannot drift apart over who the
     * caller is.
     *
     * A super-admin administers every organization (see User::administeredOrganizationIds),
     * which is what keeps an operator login able to place a shared package anywhere. An
     * unauthenticated caller administers none, so every shared package is out of reach —
     * these routes are behind `auth`/`api.auth` anyway, and the conservative answer is the
     * right one for a guard.
     *
     * @return list<string>
     */
    protected function administeredOrganizationIds(): array
    {
        $user = Auth::user();

        return $user instanceof User ? $user->administeredOrganizationIds() : [];
    }
}
