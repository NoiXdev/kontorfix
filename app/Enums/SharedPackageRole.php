<?php

namespace App\Enums;

/**
 * Who may mark a package as shared. The instance setting holds one of these; the
 * `share-packages` gate reads it.
 *
 * The default is the stricter value. Sharing is a new capability, so nobody is locked out
 * by starting strict — unlike `oidc_providers.trusts_email_claim`, where the existing rows
 * had to be backfilled permissively to avoid locking operators out of their own instance.
 * Widening is therefore a deliberate act, and only a super-admin can perform it: the
 * setting lives on `admin/system`, behind the `super` middleware, so nobody below that tier
 * can grant themselves the capability.
 *
 * The permissive tier is a *maintainer* of the operator organization, deliberately not an
 * admin. An admin whose home organization is the operator organization is already a
 * super-admin by `User::isSuperAdmin()`'s grandfather clause
 * (`role === Admin && organization?->is_operator`), so "admin vs. super-admin" was never a
 * real distinction to gate on — anyone who could satisfy it already bypasses this gate
 * entirely via `Gate::before`. A maintainer of the operator organization is the tier that
 * genuinely sits below super-admin: someone who may curate and release packages without
 * being able to do everything else. That is the delegation this setting actually offers.
 */
enum SharedPackageRole: string
{
    case SuperAdmin = 'super_admin';
    case OperatorMaintainer = 'operator_maintainer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Nur Super-Admins',
            self::OperatorMaintainer => 'Auch Maintainer der Betreiber-Organisation',
        };
    }
}
