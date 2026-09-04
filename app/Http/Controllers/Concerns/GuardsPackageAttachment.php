<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Group;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The cross-tenant assignment checks, and the two INDEPENDENT questions every write that
 * touches `group_package` has to ask.
 *
 * 1. MAY THIS ROW EXIST AT ALL? — {@see assertPackagesReachableIn}. v0.8.0's ownership rule:
 *    a package may only be attached to registries of the organization that owns it. A
 *    package marked `shared` is the one exception, because that flag exists to let an
 *    operator-organization package leave its organization.
 *
 * 2. WHO MAY CHANGE WHO HAS IT? — {@see assertSharedAssignmentsUnchanged} and
 *    {@see assertMayEditSharedAssignment}. Spec §4: sharing and assigning are two gates.
 *    `shared` says a package MAY leave its organization; the assignment says which customer
 *    actually receives it and until when, and the operator makes that decision per customer.
 *
 * The two are deliberately not merged. Question 1 is about the row, question 2 about the
 * caller, and an earlier version of this task answered both inside `assertPackagesReachableIn`
 * — which made a submission naming an ALREADY ASSIGNED shared package a refusal, because
 * that method can only see the submission and not what the registry already carries. On the
 * API's `sync()` endpoint, where the submission is the whole post-state, that left a customer
 * admin unable to send any package list at all once the operator had placed a shared package
 * in their registry: naming it was an attach they may not make, omitting it a detach they may
 * not make. The rule below has no such blind spot because it is not stated over the
 * submission.
 *
 * QUESTION 2, STATED OVER THE RESULTING SET RATHER THAN THE OPERATION:
 *
 *   the shared assignments a caller may not manage must be the SAME before and after
 *   the write.
 *
 * Same shape, and for the same reason, as App\Services\Package\SharedAssignment: a rule
 * phrased as "does this write add a shared package" has a DIRECTION and forgets the reverse —
 * a `sync()` that drops one adds nothing and would satisfy it. A set comparison has no
 * direction, so one predicate covers attach, detach, replace and swap, and each caller only
 * has to say what its pivot operation leaves behind:
 *
 *   `sync($ids)`                   → the submission
 *   `syncWithoutDetaching($ids)`   → the current assignment ∪ the submission
 *   `detach($id)`                  → the current assignment ∖ {$id}
 *   create-then-`sync($ids)`       → the submission (the registry starts empty)
 *
 * It also says exactly what spec §4 says and nothing more: a write that leaves the shared
 * assignments as they were neither attaches a shared package nor edits one, so it is not the
 * customer's to be refused.
 *
 * WHAT THE SET RULE DOES NOT COVER, and why the second method exists: `available_until` lives
 * on the pivot row, not in the set of package ids, so re-dating an assignment leaves the
 * resulting set identical and passes the comparison untouched. That is not an accident to be
 * relied on — it is the other half of spec §4's sentence ("…and editing such an assignment's
 * availability…") and it is asked separately, by {@see assertMayEditSharedAssignment}.
 *
 * Only an operator-organization package can be marked shared (Admin\PackageController::shared),
 * so "administering the owner" is in practice administering the operator organization. Both
 * methods nevertheless ask over the package's actual `organization_id`: it is the row's owner
 * the exception is granted against, and a rule asking a different question would have to be
 * kept in step with the sharing gate by hand.
 *
 * Neither question is the whole of the shared decision: a shared package may also not shadow
 * a customer's own package of the same name. That refusal lives in
 * App\Services\Package\SharedAssignment, is orthogonal to both, and must be asked separately
 * by every caller that writes a `group_package` row.
 *
 * A package is owned by an organization outright (`packages.organization_id`), so question 1
 * is a comparison rather than a reconstruction. It used to re-derive ownership from the
 * registries a package happened to be attached to, which could not answer for a package
 * attached to none: deleting a registry cascaded the pivot rows and left an orphan claimable
 * by any tenant who learned its id. Ownership survives the registries now, so that case is
 * simply a package owned by someone else.
 */
trait GuardsPackageAttachment
{
    /**
     * Aborts 403 unless every submitted package is owned by one of the given organizations,
     * or is shared. Question 1 above, unchanged since the shared-packages feature landed —
     * WHO may create such an assignment is question 2 and is not asked here.
     *
     * An empty `$orgIds` still refuses every non-empty submission of non-shared packages.
     * `assertCanAttachPackages()` in both {@see ScopesToAdministeredOrgs} and
     * {@see ScopesApiToUser} always calls this with exactly one — the organization being
     * attached into, never the caller's broader reach. Attaching creates a `group_package`
     * row the enforcement migration requires to agree with `packages.organization_id`, so
     * this holds for every caller including a super-admin: there is no organization-spanning
     * exemption for it, only for who may reach it.
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
            ->where('shared', false)
            ->whereNotIn('organization_id', $orgIds)
            ->exists();

        abort_if($foreign, 403);
    }

    /**
     * Aborts 403 unless the shared assignments this caller may not manage are identical
     * before and after the write. Question 2 above, in one directionless predicate.
     *
     * Both arguments are package-id sets for ONE registry: what it carries now, and what it
     * would carry afterwards. The caller states the second from its own pivot operation (see
     * the table in the class docblock) rather than this method guessing it, exactly as
     * SharedAssignment's callers state their post-state.
     *
     * Shared packages the caller DOES administer are absent from both sides and so are
     * unconstrained — an operator adds, moves and withdraws its own shared packages freely.
     * Non-shared packages never appear on either side; they are question 1's business.
     *
     * Compared by identity and not by size: swapping one unmanageable shared package for
     * another leaves the count alone and is exactly the write this rule exists to refuse.
     * Both sides come back ordered by id and deduplicated by the database, so `!==` is a set
     * comparison and a submission that names a package twice cannot fake a difference.
     *
     * @param  array<int, string>  $currentPackageIds  what the registry carries now
     * @param  array<int, string>  $resultingPackageIds  what it would carry after the write
     */
    protected function assertSharedAssignmentsUnchanged(array $currentPackageIds, array $resultingPackageIds): void
    {
        $administeredOrgIds = $this->administeredOrganizationIds();

        abort_if(
            $this->unmanageableSharedPackageIds($currentPackageIds, $administeredOrgIds)
                !== $this->unmanageableSharedPackageIds($resultingPackageIds, $administeredOrgIds),
            403,
        );
    }

    /**
     * Aborts 403 if the given package is shared and owned by an organization the caller does
     * not administer — the second half of spec §4's sentence, for a write that changes the
     * ASSIGNMENT ROW rather than the set of assignments.
     *
     * Only `group_package.available_until` is such a write today. It leaves membership
     * untouched, so {@see assertSharedAssignmentsUnchanged} accepts it and must: the set
     * really is unchanged. Deciding how long a customer keeps the operator's package is
     * nevertheless the operator's decision — in BOTH directions, since pushing a lapsed share
     * back into force and ending a live one are the same write — so it is asked here instead.
     *
     * Reachability is not re-asked: the pivot row already exists, so it passed
     * {@see assertPackagesReachableIn} when it was written.
     */
    protected function assertMayEditSharedAssignment(Package $package): void
    {
        abort_if(
            $this->unmanageableSharedPackageIds(
                [(string) $package->getKey()],
                $this->administeredOrganizationIds(),
            ) !== [],
            403,
        );
    }

    /**
     * The package ids a registry carries right now — the `$currentPackageIds` argument of
     * {@see assertSharedAssignmentsUnchanged}, stated once because every caller that has an
     * existing registry needs exactly this.
     *
     * `packages()`, not `assignedPackages()`: the rule is about the assignment ROW existing,
     * not about whether the registry serves it today. A lapsed shared assignment is exactly
     * as much the operator's to withdraw as a live one — and per spec §4 as amended it still
     * suppresses the name against the upstream, so detaching it is still a decision with
     * consequences for the customer. This is the same distinction
     * Admin\PackageController::shared() draws when it refuses to un-share while any
     * cross-organization row survives, expired or not, and deliberately unlike
     * SharedAssignment, which asks what a registry SERVES.
     *
     * @return list<string>
     */
    protected function currentAssignmentIds(Group $group): array
    {
        return $group->packages()->pluck('packages.id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * Of the given packages, the shared ones owned outside `$administeredOrgIds` — the only
     * rows either decision above turns on, so both are built from this one statement of
     * "shared, and not this caller's to hand out".
     *
     * Ordered by id so two calls are comparable, and returned as ids rather than as a count
     * or a boolean so the comparison can be an identity one.
     *
     * @param  array<int, string>  $packageIds
     * @param  array<int, string>  $administeredOrgIds
     * @return list<string>
     */
    private function unmanageableSharedPackageIds(array $packageIds, array $administeredOrgIds): array
    {
        if ($packageIds === []) {
            return [];
        }

        return Package::whereIn('id', $packageIds)
            ->where('shared', true)
            ->whereNotIn('organization_id', $administeredOrgIds)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * The organizations the current caller administers — the set question 2 is measured
     * against, and the one {@see ScopesToAdministeredOrgs::scopeAssignablePackageQuery()}
     * builds the picker from, so the guard and the picker cannot drift apart over who the
     * caller is.
     *
     * A super-admin administers every organization (see User::administeredOrganizationIds),
     * which is what keeps an operator login able to place a shared package anywhere. An
     * unauthenticated caller administers none, so every shared package is beyond them —
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
