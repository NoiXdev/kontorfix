<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
{
    public function view(User $user, Group $group): bool
    {
        return $this->operatorAdmin($user) || $group->organization_id === $user->organization_id;
    }

    private function operatorAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin && (bool) $user->organization?->is_operator;
    }
}
