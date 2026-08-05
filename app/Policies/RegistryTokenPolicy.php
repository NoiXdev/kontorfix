<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\RegistryToken;
use App\Models\User;

class RegistryTokenPolicy
{
    public function delete(User $user, RegistryToken $token): bool
    {
        if ($this->operatorAdmin($user)) {
            return true;
        }

        // Never an organization the user is not a member of.
        if (! $user->belongsToOrganization($token->organization_id)) {
            return false;
        }

        // Personal tokens only by the owner; org-shared (without an owner)
        // only by org admin/maintainer.
        return $token->user_id === null
            ? in_array($user->role, [UserRole::Admin, UserRole::Maintainer], true)
            : $token->user_id === $user->id;
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
