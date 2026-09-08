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

    /** Whether this rule vetoes deletion rather than participating in the keep-OR. */
    public function isShield(): bool
    {
        return $this === self::NeverDelete;
    }

    /** German label, rendered in the editor and in the dry run's reason column. */
    public function label(): string
    {
        return match ($this) {
            self::KeepLast => 'Letzte N behalten',
            self::KeepNewerThanDays => 'Jünger als N Tage',
            self::KeepMatching => 'Passend zu Muster',
            self::NeverDelete => 'Nie löschen',
        };
    }

    /**
     * Every type with its label and whether it is a shield, for the editor to render the
     * two groups from. The frontend must not restate which types are shields — that is the
     * one fact the evaluator's safety property rests on, and two copies of it would drift.
     *
     * @return list<array{value: string, label: string, shield: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'shield' => $case->isShield(),
        ], self::cases());
    }
}
