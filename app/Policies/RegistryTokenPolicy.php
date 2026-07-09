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

        // Fremde Org niemals.
        if ($user->organization_id === null || $token->organization_id !== $user->organization_id) {
            return false;
        }

        // Persönliche Tokens nur durch den Besitzer; org-geteilte (ohne Besitzer)
        // nur durch Org-Admin/Maintainer.
        return $token->user_id === null
            ? in_array($user->role, [UserRole::Admin, UserRole::Maintainer], true)
            : $token->user_id === $user->id;
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
