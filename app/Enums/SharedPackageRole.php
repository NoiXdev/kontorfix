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
 * setting lives on `admin/system`, behind the `super` middleware, so an operator-org admin
 * cannot grant themselves the capability.
 */
enum SharedPackageRole: string
{
    case SuperAdmin = 'super_admin';
    case OperatorAdmin = 'operator_admin';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Nur Super-Admins',
            self::OperatorAdmin => 'Auch Admins der Betreiber-Organisation',
        };
    }
}
