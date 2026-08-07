<?php

namespace App\Policies;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\RegistryToken;
use App\Models\User;

class RegistryTokenPolicy
{
    /**
     * Whether the user may issue a registry token for the given organization with the
     * given ability.
     *
     * A read token is self-service: anyone who belongs to the organization may pull with
     * it. A publish token, however, is a write credential for the organization's
     * registries — it injects package versions that every colleague then resolves. It is
     * therefore admin/maintainer-only, matching the admin console and the /api/v1 surface
     * (both already behind the `operator` middleware). Membership alone is not enough,
     * and the role is evaluated per organization: a maintainer at home who is only a
     * member of an additional org cannot mint a publish token there.
     */
    public function create(User $user, ?string $organizationId, TokenAbility $ability): bool
    {
        if ($organizationId === null || ! $user->belongsToOrganization($organizationId)) {
            return false;
        }

        return $ability !== TokenAbility::Publish || $user->administers($organizationId);
    }

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
