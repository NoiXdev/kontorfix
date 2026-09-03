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

    /**
     * The label an operator reads on `admin/system` before choosing, so both halves have to
     * name the same population the gate does.
     *
     * The strict label says whom it already includes, because "only super-admins" alone
     * invites the reading that an Admin of the operator organization is gated by it. They
     * are not: the grandfather clause above makes them a super-admin, so they may share at
     * either value and this setting never applied to them.
     *
     * The permissive label therefore names maintainers and nothing else. Wording it as
     * "additionally admins of the operator organization" — the spec's original axis — would
     * state a delegation that does not exist and imply the strict value withheld something
     * from them.
     */
    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Nur Super-Admins (Admins der Betreiber-Organisation sind das bereits)',
            self::OperatorMaintainer => 'Zusätzlich Maintainer der Betreiber-Organisation',
        };
    }

    /**
     * Both labels, for the settings page's select. Same `{value, label}` shape as
     * {@see PackageType::metadata()} and {@see NotificationEvent}, so the frontend renders
     * the options it is given rather than restating the two cases.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
