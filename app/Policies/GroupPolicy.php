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

        // Collection-only groups (portal disabled) are a container for packages that
        // get composed into other registries — they are not themselves a portal-visible
        // registry, so members must not reach them through the portal.
        return $group->portal_enabled
            && $group->organization_id !== null
            && $user->belongsToOrganization($group->organization_id);
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
