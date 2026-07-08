<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\RegistryToken;
use App\Models\User;

class RegistryTokenPolicy
{
    public function delete(User $user, RegistryToken $token): bool
    {
        return $this->operatorAdmin($user)
            || ($user->organization_id !== null && $token->organization_id === $user->organization_id);
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
