<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        if ($this->operatorAdmin($user)) {
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

        // Collection-only groups (portal disabled) are a container for packages that
        // get composed into other registries — they are not themselves a portal-visible
        // registry, so members must not reach them through the portal.
        return $group->portal_enabled
            && $groupOrganizationId !== null
            && $user->belongsToOrganization($groupOrganizationId);
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
