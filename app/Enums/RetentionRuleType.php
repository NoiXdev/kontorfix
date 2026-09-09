<?php

namespace App\Enums;

/**
 * The four rule types a retention policy is built from.
 *
 * `NeverDelete` is separated from the other three by isShield(), and the separation is
 * mechanical rather than cosmetic — see App\Services\Oci\Retention\RetentionEvaluator.
 * Under a flat four-way OR, a policy consisting of nothing but `never_delete: prod-*`
 * would keep the prod tags and DELETE EVERY OTHER TAG in the repository, so an operator
 * who added a shield would have configured a deletion. A shield therefore vetoes; it does
 * not select.
 */
enum RetentionRuleType: string
{
    case KeepLast = 'keep_last';
    case KeepNewerThanDays = 'keep_newer_than_days';
    case KeepMatching = 'keep_matching';
    case NeverDelete = 'never_delete';
    // Decides MANIFESTS, not tags: an untagged, nowhere-referenced manifest survives N
    // days instead of only the grace period (Harbor parity, an operator decision taken
    // with the stated cost that the policy layer now influences which manifests live).
    // affectsTags() below is what keeps it out of the evaluator's tag OR.
    case KeepUntagged = 'keep_untagged';

    /** Whether this rule vetoes deletion rather than participating in the keep-OR. */
    public function isShield(): bool
    {
        return $this === self::NeverDelete;
    }

    /**
     * Whether this rule participates in tag decisions at all. KeepUntagged does not — it
     * yields a per-package manifest window (ResolvedRetention::untaggedKeepDays()), which
     * the sweeper consumes as a timestamp. Treated as a tag keep-rule it would keep every
     * tag or none, and either reading corrupts the OR.
     */
    public function affectsTags(): bool
    {
        return $this !== self::KeepUntagged;
    }

    /** German label, rendered in the editor and in the dry run's reason column. */
    public function label(): string
    {
        return match ($this) {
            self::KeepLast => 'Letzte N behalten',
            self::KeepNewerThanDays => 'Jünger als N Tage',
            self::KeepMatching => 'Passend zu Muster',
            self::NeverDelete => 'Nie löschen',
            self::KeepUntagged => 'Ungetaggte behalten (Tage)',
        };
    }

    /**
     * Every type with its label and whether it is a shield, for the editor to render the
     * two groups from. The frontend must not restate which types are shields — that is the
     * one fact the evaluator's safety property rests on, and two copies of it would drift.
     *
     * @return list<array{value: string, label: string, shield: bool, untagged: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'shield' => $case->isShield(),
            // The editor renders the three groups (keep / shield / untagged) from these
            // two flags — which types belong where is the server's fact, stated once.
            'untagged' => ! $case->affectsTags(),
        ], self::cases());
    }
}
