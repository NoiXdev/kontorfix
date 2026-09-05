<?php

namespace App\Policies;

use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        // Collection-only groups (portal disabled) are a container for packages that get
        // composed into other registries — they are not themselves a portal-visible registry.
        //
        // AHEAD OF THE OPERATOR BRANCH, and that ordering is the point. The operator branch
        // used to sit first, which was harmless only because it was unreachable: its old
        // condition was character-for-character User::isSuperAdmin()'s second clause, and a
        // super-admin never gets here at all — AppServiceProvider's Gate::before answers
        // first. Widening it to every operator account made the branch live, and an operator
        // stepping past this check would see a registry the portal deliberately hides from
        // the customer whose portal they are looking at. Spec decision 4 says an operator
        // sees exactly what the customer sees, and index() and PortalPackages both filter on
        // this same column, so no legitimate operator path wants a hidden group.
        //
        // NO LONGER REACHED THROUGH ANY ROUTE. All three portal paths —
        // RegistryController::show(), showPackage() and TokenController::store() — state
        // `abort_unless($group->portal_enabled, 404)` before they authorize, because
        // ENFORCEMENT of a surface property belongs in the controller, ahead of
        // authorization, where every population gets one answer. That is Task 4's ruling and
        // it is about where the rule is enforced, not about whether this policy may also
        // state it.
        //
        // Kept, and pinned directly: GroupPolicyTest's 'refuses to view a group the portal
        // does not show' calls this method rather than a route, so deleting the clause
        // reddens a named test. That test is what keeps it, not this comment.
        if (! $group->portal_enabled) {
            return false;
        }

        // The same question ResolvePortalContext asks before it opens a customer portal at
        // all, asked through the same method. This policy is reached only from the portal
        // controllers (Portal\RegistryController and Portal\TokenController are its only
        // callers), so the two answering differently meant exactly one thing: an account
        // admitted to /c/{customer} and then answered 403 on every page inside it.
        if ($user->administersOperatorOrganization()) {
            return true;
        }

        // groups.organization_id is database-enforced NOT NULL (see the
        // 2026_09_02_110000_enforce_package_organization migration) for a *persisted* row —
        // but this method takes a plain model, and Eloquent never guarantees every attribute
        // of an in-memory one is set. getAttribute() reads the raw value instead of the
        // magic property, whose declared type PHPStan infers from that same NOT NULL schema
        // and would otherwise treat as always non-null, masking exactly the case this
        // guards against. belongsToOrganization() takes a non-nullable string, so dropping
        // this check would not silently grant access — it would throw a TypeError for an
        // in-memory Group with no organization, which is not a graceful "not viewable"
        // either. Same shape as RegistryAccessService::canAccessGroup().
        $groupOrganizationId = $group->getAttribute('organization_id');

        return $groupOrganizationId !== null
            && $user->belongsToOrganization($groupOrganizationId);
    }
}
